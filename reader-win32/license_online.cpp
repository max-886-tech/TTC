#include "license_online.h"

#include <windows.h>
#include <winhttp.h>
#pragma comment(lib, "winhttp.lib")

#include <string>
#include <vector>
#include <algorithm>
#include <sstream>
#include <cctype>

#include <shlwapi.h>
#pragma comment(lib, "Shlwapi.lib")

namespace ttc::reader::license {

// -------------------------
// UTF helpers
// -------------------------
static std::wstring Utf8ToWide(const std::string& s) {
    if (s.empty()) return L"";
    int n = MultiByteToWideChar(CP_UTF8, 0, s.data(), (int)s.size(), nullptr, 0);
    std::wstring w(n, L'\0');
    MultiByteToWideChar(CP_UTF8, 0, s.data(), (int)s.size(), w.data(), n);
    return w;
}

static std::string WideToUtf8(const std::wstring& w) {
    if (w.empty()) return "";
    int n = WideCharToMultiByte(CP_UTF8, 0, w.data(), (int)w.size(), nullptr, 0, nullptr, nullptr);
    std::string s(n, '\0');
    WideCharToMultiByte(CP_UTF8, 0, w.data(), (int)w.size(), s.data(), n, nullptr, nullptr);
    return s;
}

// -------------------------
// URL encoding
// -------------------------
static std::string UrlEncode(const std::string& in) {
    static const char hex[] = "0123456789ABCDEF";
    std::string out;
    out.reserve(in.size() * 2);

    for (unsigned char c : in) {
        if ((c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') ||
            (c >= '0' && c <= '9') || c == '-' || c == '_' || c == '.' || c == '~') {
            out.push_back((char)c);
        } else if (c == ' ') {
            out.push_back('+');
        } else {
            out.push_back('%');
            out.push_back(hex[c >> 4]);
            out.push_back(hex[c & 15]);
        }
    }
    return out;
}

static std::string Trim(const std::string& s) {
    size_t b = 0, e = s.size();
    while (b < e && std::isspace((unsigned char)s[b])) b++;
    while (e > b && std::isspace((unsigned char)s[e - 1])) e--;
    return s.substr(b, e - b);
}

// -------------------------
// Minimal JSON extraction (no external libs)
// Supports keys like: "ok":true, "exam_name":"..", "user_phone":null
// -------------------------
static bool JsonFindKeyPos(const std::string& json, const std::string& key, size_t& outPos) {
    // look for "key" or 'key'
    std::string k1 = "\"" + key + "\"";
    std::string k2 = "'" + key + "'";
    size_t p = json.find(k1);
    if (p == std::string::npos) p = json.find(k2);
    if (p == std::string::npos) return false;
    outPos = p + k1.size();
    // if matched single quote, adjust length
    if (json[p] == '\'') outPos = p + k2.size();
    return true;
}

static void SkipWs(const std::string& s, size_t& i) {
    while (i < s.size() && std::isspace((unsigned char)s[i])) i++;
}

static bool JsonReadString(const std::string& s, size_t& i, std::string& out) {
    out.clear();
    if (i >= s.size() || s[i] != '"') return false;
    i++; // skip "
    while (i < s.size()) {
        char c = s[i++];
        if (c == '"') return true;
        if (c == '\\' && i < s.size()) {
            char e = s[i++];
            switch (e) {
            case '"': out.push_back('"'); break;
            case '\\': out.push_back('\\'); break;
            case '/': out.push_back('/'); break;
            case 'b': out.push_back('\b'); break;
            case 'f': out.push_back('\f'); break;
            case 'n': out.push_back('\n'); break;
            case 'r': out.push_back('\r'); break;
            case 't': out.push_back('\t'); break;
            default:  out.push_back(e); break; // minimal
            }
        } else {
            out.push_back(c);
        }
    }
    return false;
}

static bool JsonGetString(const std::string& json, const std::string& key, std::string& out) {
    out.clear();
    size_t pos = 0;
    if (!JsonFindKeyPos(json, key, pos)) return false;

    // find ':'
    size_t i = json.find(':', pos);
    if (i == std::string::npos) return false;
    i++;
    SkipWs(json, i);

    if (i >= json.size()) return false;

    if (json.compare(i, 4, "null") == 0) { out.clear(); return true; }
    if (json[i] != '"') return false;

    return JsonReadString(json, i, out);
}

static bool JsonGetBool(const std::string& json, const std::string& key, bool& out) {
    out = false;
    size_t pos = 0;
    if (!JsonFindKeyPos(json, key, pos)) return false;

    size_t i = json.find(':', pos);
    if (i == std::string::npos) return false;
    i++;
    SkipWs(json, i);

    if (json.compare(i, 4, "true") == 0) { out = true; return true; }
    if (json.compare(i, 5, "false") == 0) { out = false; return true; }
    return false;
}

// -------------------------
// Minimal URL parse for WinHTTP
// -------------------------
struct ParsedUrl {
    std::wstring scheme;
    std::wstring host;
    std::wstring pathAndQuery;
    INTERNET_PORT port = 443;
    bool https = true;
};

static bool ParseUrl(const std::wstring& url, ParsedUrl& out) {
    URL_COMPONENTSW uc{};
    wchar_t host[256];
    wchar_t path[2048];
    wchar_t scheme[16];

    uc.dwStructSize = sizeof(uc);
    uc.lpszHostName = host; uc.dwHostNameLength = _countof(host);
    uc.lpszUrlPath  = path; uc.dwUrlPathLength  = _countof(path);
    uc.lpszScheme   = scheme; uc.dwSchemeLength = _countof(scheme);

    std::wstring tmp = url;
    if (!WinHttpCrackUrl(tmp.c_str(), (DWORD)tmp.size(), 0, &uc)) return false;

    out.scheme.assign(uc.lpszScheme, uc.dwSchemeLength);
    out.host.assign(uc.lpszHostName, uc.dwHostNameLength);

    std::wstring urlPath(uc.lpszUrlPath, uc.dwUrlPathLength);
    std::wstring extra;
    if (uc.dwExtraInfoLength && uc.lpszExtraInfo) extra.assign(uc.lpszExtraInfo, uc.dwExtraInfoLength);
    out.pathAndQuery = urlPath + extra;

    out.port = uc.nPort;
    out.https = (_wcsicmp(out.scheme.c_str(), L"https") == 0);
    return !out.host.empty() && !out.pathAndQuery.empty();
}

// -------------------------
// HTTP
// -------------------------
static bool WinHttpPostForm(
    const ServerConfig& cfg,
    const std::string& body,
    int& outStatus,
    std::string& outResp,
    std::string& outErr
) {
    outStatus = 0;
    outResp.clear();
    outErr.clear();

    HINTERNET hSession = WinHttpOpen(
        cfg.userAgent.c_str(),
        WINHTTP_ACCESS_TYPE_DEFAULT_PROXY,
        WINHTTP_NO_PROXY_NAME,
        WINHTTP_NO_PROXY_BYPASS,
        0
    );
    if (!hSession) { outErr = "WinHttpOpen failed"; return false; }

    WinHttpSetTimeouts(hSession, cfg.timeoutMs, cfg.timeoutMs, cfg.timeoutMs, cfg.timeoutMs);

    HINTERNET hConnect = WinHttpConnect(hSession, cfg.host.c_str(), cfg.port, 0);
    if (!hConnect) {
        outErr = "WinHttpConnect failed";
        WinHttpCloseHandle(hSession);
        return false;
    }

    DWORD flags = cfg.useHttps ? WINHTTP_FLAG_SECURE : 0;
    HINTERNET hRequest = WinHttpOpenRequest(
        hConnect, L"POST", cfg.path.c_str(), nullptr,
        WINHTTP_NO_REFERER, WINHTTP_DEFAULT_ACCEPT_TYPES, flags
    );
    if (!hRequest) {
        outErr = "WinHttpOpenRequest failed";
        WinHttpCloseHandle(hConnect);
        WinHttpCloseHandle(hSession);
        return false;
    }

    std::wstring headers = L"Content-Type: application/x-www-form-urlencoded\r\n";

    BOOL ok = WinHttpSendRequest(
        hRequest,
        headers.c_str(),
        (DWORD)headers.size(),
        (LPVOID)body.data(),
        (DWORD)body.size(),
        (DWORD)body.size(),
        0
    );

    if (!ok || !WinHttpReceiveResponse(hRequest, nullptr)) {
        outErr = "HTTP request failed (send/receive)";
        WinHttpCloseHandle(hRequest);
        WinHttpCloseHandle(hConnect);
        WinHttpCloseHandle(hSession);
        return false;
    }

    DWORD status = 0, statusSize = sizeof(status);
    if (WinHttpQueryHeaders(
            hRequest,
            WINHTTP_QUERY_STATUS_CODE | WINHTTP_QUERY_FLAG_NUMBER,
            WINHTTP_HEADER_NAME_BY_INDEX,
            &status,
            &statusSize,
            WINHTTP_NO_HEADER_INDEX
        )) {
        outStatus = (int)status;
    }

    for (;;) {
        DWORD avail = 0;
        if (!WinHttpQueryDataAvailable(hRequest, &avail)) break;
        if (avail == 0) break;

        std::string chunk(avail, '\0');
        DWORD read = 0;
        if (!WinHttpReadData(hRequest, chunk.data(), avail, &read) || read == 0) break;
        chunk.resize(read);
        outResp += chunk;
    }

    WinHttpCloseHandle(hRequest);
    WinHttpCloseHandle(hConnect);
    WinHttpCloseHandle(hSession);
    return true;
}

// -------------------------
// Validate + parse
// -------------------------
static bool ValidateInternalInfo(
    const ServerConfig& cfg,
    const std::string& examIdUtf8,
    const std::string& codeUtf8,
    const std::string* deviceIdUtf8,
    LicenseInfo& outInfo,
    std::string& outError
) {
    outError.clear();
    outInfo = LicenseInfo{};

    const std::string examId = Trim(examIdUtf8);
    const std::string code   = Trim(codeUtf8);

    if (examId.empty()) { outError = "Missing examId"; return false; }
    if (code.empty())   { outError = "Missing password/code"; return false; }

    std::string body = "exam_id=" + UrlEncode(examId) + "&code=" + UrlEncode(code);
    if (deviceIdUtf8 && !deviceIdUtf8->empty()) body += "&device_id=" + UrlEncode(*deviceIdUtf8);

    int httpStatus = 0;
    std::string resp, err;
    if (!WinHttpPostForm(cfg, body, httpStatus, resp, err)) {
        outError = err;
        return false;
    }

    outInfo.raw_json = resp;

    // Try parse whatever we can (even on non-200)
    JsonGetBool(resp, "ok", outInfo.ok);
    JsonGetString(resp, "message", outInfo.message);
    if (outInfo.message.empty()) JsonGetString(resp, "error", outInfo.message);

    JsonGetString(resp, "expires_at", outInfo.expires_at);
    JsonGetString(resp, "exam_id", outInfo.exam_id);
    JsonGetString(resp, "exam_name", outInfo.exam_name);
    JsonGetString(resp, "user_email", outInfo.user_email);
    JsonGetString(resp, "user_phone", outInfo.user_phone);
    JsonGetString(resp, "user_address", outInfo.user_address);

    // Optional app update info
    JsonGetString(resp, "latest_version", outInfo.latest_version);
    JsonGetString(resp, "min_version", outInfo.min_version);
    JsonGetString(resp, "update_url", outInfo.update_url);
    JsonGetBool(resp, "force_update", outInfo.force_update);

    // If server didn’t return exam_id, fallback to what we sent
    if (outInfo.exam_id.empty()) outInfo.exam_id = examId;

    // Enforce: 200 + ok:true
    if (httpStatus != 200) {
        std::ostringstream oss;
        oss << "License server rejected (HTTP " << httpStatus << "). ";
        if (!outInfo.message.empty()) oss << outInfo.message;
        else if (!resp.empty()) oss << "Resp: " << resp.substr(0, 200);
        outError = oss.str();
        return false;
    }

    if (!outInfo.ok) {
        if (!outInfo.message.empty()) outError = outInfo.message;
        else outError = "License check failed.";
        return false;
    }

    return true;
}

// Public APIs
bool ValidateAccessCodeInfo(
    const ServerConfig& cfg,
    const std::string& examIdUtf8,
    const std::string& codeUtf8,
    LicenseInfo& outInfo,
    std::string& outError
) {
    return ValidateInternalInfo(cfg, examIdUtf8, codeUtf8, nullptr, outInfo, outError);
}

bool ValidateAccessCodeWithDeviceInfo(
    const ServerConfig& cfg,
    const std::string& examIdUtf8,
    const std::string& codeUtf8,
    const std::string& deviceIdUtf8,
    LicenseInfo& outInfo,
    std::string& outError
) {
    return ValidateInternalInfo(cfg, examIdUtf8, codeUtf8, &deviceIdUtf8, outInfo, outError);
}

// Backward compatible wrappers
bool ValidateAccessCode(
    const ServerConfig& cfg,
    const std::string& examIdUtf8,
    const std::string& codeUtf8,
    std::string& outError
) {
    LicenseInfo info;
    return ValidateAccessCodeInfo(cfg, examIdUtf8, codeUtf8, info, outError);
}

bool ValidateAccessCodeWithDevice(
    const ServerConfig& cfg,
    const std::string& examIdUtf8,
    const std::string& codeUtf8,
    const std::string& deviceIdUtf8,
    std::string& outError
) {
    LicenseInfo info;
    return ValidateAccessCodeWithDeviceInfo(cfg, examIdUtf8, codeUtf8, deviceIdUtf8, info, outError);
}

// -------------------------
// Download URL (get_download.php)
// -------------------------
bool GetDownloadUrl(
    const ServerConfig& cfg,
    const std::string& codeUtf8,
    const std::string& deviceIdUtf8,
    std::string& outUrl,
    std::string& outError
) {
    outUrl.clear();
    outError.clear();

    const std::string code = Trim(codeUtf8);
    if (code.empty()) { outError = "Missing code"; return false; }

    std::string body = "code=" + UrlEncode(code);
    if (!deviceIdUtf8.empty()) body += "&device_id=" + UrlEncode(deviceIdUtf8);

    int status = 0;
    std::string resp, err;
    if (!WinHttpPostForm(cfg, body, status, resp, err)) {
        outError = err.empty() ? "Request failed" : err;
        return false;
    }

    bool ok = false;
    JsonGetBool(resp, "ok", ok);
    if (!ok) {
        std::string msg;
        if (!JsonGetString(resp, "error", msg)) JsonGetString(resp, "message", msg);
        if (msg.empty()) msg = "Download not allowed";
        outError = msg;
        return false;
    }

    std::string url;
    if (!JsonGetString(resp, "url", url) || url.empty()) {
        outError = "Server did not return url";
        return false;
    }
    outUrl = url;
    return true;
}

// -------------------------
// Download URL -> file
// -------------------------
bool DownloadUrlToFile(
    const std::wstring& url,
    const std::wstring& outPath,
    int timeoutMs,
    std::string& outError
) {
    outError.clear();

    ParsedUrl p;
    if (!ParseUrl(url, p)) {
        outError = "Invalid download URL";
        return false;
    }

    HANDLE hFile = CreateFileW(outPath.c_str(), GENERIC_WRITE, 0, NULL, CREATE_ALWAYS, FILE_ATTRIBUTE_NORMAL, NULL);
    if (hFile == INVALID_HANDLE_VALUE) {
        DWORD e = GetLastError();
        outError = "Cannot write downloaded file (err=" + std::to_string((int)e) + ")";
        return false;
    }

    HINTERNET hSession = WinHttpOpen(
        L"TrueCerts-Reader/1.0",
        WINHTTP_ACCESS_TYPE_DEFAULT_PROXY,
        WINHTTP_NO_PROXY_NAME,
        WINHTTP_NO_PROXY_BYPASS,
        0);
    if (!hSession) {
        CloseHandle(hFile);
        outError = "WinHttpOpen failed";
        return false;
    }

    WinHttpSetTimeouts(hSession, timeoutMs, timeoutMs, timeoutMs, timeoutMs);

    HINTERNET hConnect = WinHttpConnect(hSession, p.host.c_str(), p.port, 0);
    if (!hConnect) {
        WinHttpCloseHandle(hSession);
        CloseHandle(hFile);
        outError = "WinHttpConnect failed";
        return false;
    }

    DWORD flags = p.https ? WINHTTP_FLAG_SECURE : 0;
    HINTERNET hReq = WinHttpOpenRequest(
        hConnect,
        L"GET",
        p.pathAndQuery.c_str(),
        nullptr,
        WINHTTP_NO_REFERER,
        WINHTTP_DEFAULT_ACCEPT_TYPES,
        flags);

    if (!hReq) {
        WinHttpCloseHandle(hConnect);
        WinHttpCloseHandle(hSession);
        CloseHandle(hFile);
        outError = "WinHttpOpenRequest failed";
        return false;
    }

    BOOL ok = WinHttpSendRequest(hReq, WINHTTP_NO_ADDITIONAL_HEADERS, 0, WINHTTP_NO_REQUEST_DATA, 0, 0, 0);
    if (!ok || !WinHttpReceiveResponse(hReq, nullptr)) {
        WinHttpCloseHandle(hReq);
        WinHttpCloseHandle(hConnect);
        WinHttpCloseHandle(hSession);
        CloseHandle(hFile);
        outError = "HTTP download request failed";
        return false;
    }

    DWORD status = 0, statusSize = sizeof(status);
    WinHttpQueryHeaders(hReq, WINHTTP_QUERY_STATUS_CODE | WINHTTP_QUERY_FLAG_NUMBER,
        WINHTTP_HEADER_NAME_BY_INDEX, &status, &statusSize, WINHTTP_NO_HEADER_INDEX);
    if (status < 200 || status >= 300) {
        WinHttpCloseHandle(hReq);
        WinHttpCloseHandle(hConnect);
        WinHttpCloseHandle(hSession);
        CloseHandle(hFile);
        outError = "Download HTTP " + std::to_string((int)status);
        return false;
    }

    std::vector<char> buf(64 * 1024);
    for (;;) {
        DWORD avail = 0;
        if (!WinHttpQueryDataAvailable(hReq, &avail)) break;
        if (avail == 0) break;

        DWORD toRead = (DWORD)std::min<size_t>(buf.size(), (size_t)avail);
        DWORD read = 0;
        if (!WinHttpReadData(hReq, buf.data(), toRead, &read) || read == 0) break;

        DWORD written = 0;
        if (!WriteFile(hFile, buf.data(), read, &written, NULL) || written != read) {
            DWORD e = GetLastError();
            outError = "WriteFile failed (err=" + std::to_string((int)e) + ")";
            WinHttpCloseHandle(hReq);
            WinHttpCloseHandle(hConnect);
            WinHttpCloseHandle(hSession);
            CloseHandle(hFile);
            return false;
        }
    }

    WinHttpCloseHandle(hReq);
    WinHttpCloseHandle(hConnect);
    WinHttpCloseHandle(hSession);
    CloseHandle(hFile);
    return true;
}

} // namespace ttc::reader::license
