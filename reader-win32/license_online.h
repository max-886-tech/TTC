#pragma once
#include <string>
#include <cstdint>

namespace ttc::reader::license {

struct ServerConfig {
    std::wstring host;
    std::wstring path;

    unsigned short port = 443;
    bool useHttps = true;

    int timeoutMs = 8000;
    std::wstring userAgent = L"TTC-Reader/1.0";
};

// Data returned by server on success (or partial info even on failure).
struct LicenseInfo {
    bool ok = false;

    // Optional server fields (recommended to return from PHP)
    std::string message;      // error/message from server
    std::string expires_at;   // UTC string, e.g. "2026-02-01 12:00:00"

    std::string dump_id;
    std::string dump_name;

    std::string user_email;
    std::string user_phone;
    std::string user_address;

    // App update fields (optional from validate.php)
    std::string latest_version; // e.g. "1.2.0"
    std::string min_version;    // e.g. "1.0.0" (force update if app < min)
    std::string update_url;     // e.g. "https://thetruecerts.com/reader/latest"
    bool force_update = false;  // optional

    // raw json (for debugging)
    std::string raw_json;
};

// -------------------------
// Download API (get_download.php)
// -------------------------
// Returns a (typically presigned) URL for downloading the .ttc file for an access code.
bool GetDownloadUrl(
    const ServerConfig& cfg,
    const std::string& codeUtf8,
    const std::string& deviceIdUtf8,
    std::string& outUrl,
    std::string& outError
);

// Downloads the URL to a local file path (creates/overwrites the file).
using ProgressCallback = void(*)(uint64_t downloaded, uint64_t total, void* user);
bool DownloadUrlToFile(
    const std::wstring& url,
    const std::wstring& outPath,
    int timeoutMs,
    std::string& outError,
    void* progressUser = nullptr,
    ProgressCallback progressCb = nullptr
);

// New: Validate + parse JSON fields
bool ValidateAccessCodeInfo(
    const ServerConfig& cfg,
    const std::string& dumpIdUtf8,
    const std::string& codeUtf8,
    LicenseInfo& outInfo,
    std::string& outError
);

bool ValidateAccessCodeWithDeviceInfo(
    const ServerConfig& cfg,
    const std::string& dumpIdUtf8,
    const std::string& codeUtf8,
    const std::string& deviceIdUtf8,
    LicenseInfo& outInfo,
    std::string& outError
);

// Backward compatible APIs (still supported)
bool ValidateAccessCode(
    const ServerConfig& cfg,
    const std::string& dumpIdUtf8,
    const std::string& codeUtf8,
    std::string& outError
);

bool ValidateAccessCodeWithDevice(
    const ServerConfig& cfg,
    const std::string& dumpIdUtf8,
    const std::string& codeUtf8,
    const std::string& deviceIdUtf8,
    std::string& outError
);

} // namespace ttc::reader::license
