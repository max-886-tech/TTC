// ---- MUST be before any Windows headers ----
#ifndef _WIN32_WINNT
#define _WIN32_WINNT 0x0600
#endif

#ifndef NOMINMAX
#define NOMINMAX
#endif

// Winsock must be before windows.h
#include <winsock2.h>
#include <ws2tcpip.h>

#include <windows.h>
#include <iphlpapi.h>
#include <sddl.h>

// ---- COM headers needed by GDI+ ----
#include <unknwn.h>
#include <objidl.h>
#include <propidl.h>

// Now GDI+
#include <gdiplus.h>

#include <string>
#include <vector>
#include <mutex>

#include "watermark.h"

#pragma comment(lib, "advapi32.lib")
#pragma comment(lib, "iphlpapi.lib")
#pragma comment(lib, "gdiplus.lib")

#ifndef GAA_FLAG_SKIP_ANYCAST
#define GAA_FLAG_SKIP_ANYCAST 0
#endif
#ifndef GAA_FLAG_SKIP_MULTICAST
#define GAA_FLAG_SKIP_MULTICAST 0
#endif
#ifndef GAA_FLAG_SKIP_DNS_SERVER
#define GAA_FLAG_SKIP_DNS_SERVER 0
#endif

namespace ttc::reader {

// ---------------- GDI+ init ----------------
static ULONG_PTR g_gdiplusToken = 0;
static void EnsureGdiPlus() {
    static std::once_flag once;
    std::call_once(once, []() {
        Gdiplus::GdiplusStartupInput si;
        Gdiplus::GdiplusStartup(&g_gdiplusToken, &si, nullptr);
    });
}

// ---------------- device/user info helpers ----------------
static std::wstring GetUserNameWStr() {
    wchar_t buf[256]; DWORD n = 256;
    if (GetUserNameW(buf, &n)) return buf;
    return L"unknown";
}

static std::wstring GetComputerNameWStr() {
    wchar_t buf[256]; DWORD n = 256;
    if (GetComputerNameW(buf, &n)) return buf;
    return L"PC";
}

static std::wstring GetMachineGuidWStr() {
    HKEY h = nullptr;
    if (RegOpenKeyExW(HKEY_LOCAL_MACHINE, L"SOFTWARE\\Microsoft\\Cryptography", 0,
                      KEY_READ | KEY_WOW64_64KEY, &h) != ERROR_SUCCESS) {
        return L"";
    }
    wchar_t buf[256]; DWORD cb = sizeof(buf);
    LONG r = RegGetValueW(h, nullptr, L"MachineGuid", RRF_RT_REG_SZ, nullptr, buf, &cb);
    RegCloseKey(h);
    if (r == ERROR_SUCCESS) return buf;
    return L"";
}

static std::wstring GetUserSidWStr() {
    HANDLE tok = nullptr;
    if (!OpenProcessToken(GetCurrentProcess(), TOKEN_QUERY, &tok)) return L"";
    DWORD sz = 0;
    GetTokenInformation(tok, TokenUser, nullptr, 0, &sz);
    std::vector<BYTE> buf(sz);
    if (!GetTokenInformation(tok, TokenUser, buf.data(), (DWORD)buf.size(), &sz)) {
        CloseHandle(tok);
        return L"";
    }
    CloseHandle(tok);

    auto* tu = (TOKEN_USER*)buf.data();
    LPWSTR sidStr = nullptr;
    if (!ConvertSidToStringSidW(tu->User.Sid, &sidStr)) return L"";
    std::wstring out = sidStr;
    LocalFree(sidStr);
    return out;
}

static std::wstring GetLocalIPv4WStr() {
    ULONG flags = GAA_FLAG_SKIP_ANYCAST | GAA_FLAG_SKIP_MULTICAST | GAA_FLAG_SKIP_DNS_SERVER;
    ULONG sz = 0;
    GetAdaptersAddresses(AF_INET, flags, nullptr, nullptr, &sz);
    if (sz == 0) return L"";
    std::vector<BYTE> mem(sz);
    IP_ADAPTER_ADDRESSES* aa = (IP_ADAPTER_ADDRESSES*)mem.data();
    if (GetAdaptersAddresses(AF_INET, flags, nullptr, aa, &sz) != NO_ERROR) return L"";

    for (auto* a = aa; a; a = a->Next) {
        if (a->IfType == IF_TYPE_SOFTWARE_LOOPBACK) continue;
        for (auto* u = a->FirstUnicastAddress; u; u = u->Next) {
            SOCKADDR* sa = u->Address.lpSockaddr;
            if (!sa || sa->sa_family != AF_INET) continue;
            auto* in = (sockaddr_in*)sa;
            unsigned char* b = (unsigned char*)&in->sin_addr.S_un.S_addr;

            if (b[0] == 127) continue;
            if (b[0] == 169 && b[1] == 254) continue;

            wchar_t ip[64];
            wsprintfW(ip, L"%u.%u.%u.%u", b[0], b[1], b[2], b[3]);
            return ip;
        }
    }
    return L"";
}

static std::wstring NowLocalTimeShort() {
    SYSTEMTIME st{};
    GetLocalTime(&st);
    wchar_t b[64];
    wsprintfW(b, L"%04u-%02u-%02u %02u:%02u",
              st.wYear, st.wMonth, st.wDay, st.wHour, st.wMinute);
    return b;
}

static uint64_t Fnv1a64(const wchar_t* s) {
    uint64_t h = 1469598103934665603ULL;
    while (*s) {
        uint16_t c = (uint16_t)*s++;
        h ^= c;
        h *= 1099511628211ULL;
    }
    return h;
}

static std::wstring Hex64(uint64_t v) {
    wchar_t b[32];
    wsprintfW(b, L"%016llX", (unsigned long long)v);
    return b;
}

static std::wstring Tail(const std::wstring& s, size_t n) {
    if (s.size() <= n) return s;
    return s.substr(s.size() - n);
}

// ---------------- public: build watermark ----------------
std::wstring BuildAntiShareWatermark(
    const std::wstring& dumpName,
    const std::wstring& dumpId,
    const std::wstring& userTag,
    const std::wstring& orderId
) {
    std::wstring user = GetUserNameWStr();
    std::wstring pc   = GetComputerNameWStr();
    std::wstring ip   = GetLocalIPv4WStr();
    std::wstring mg   = GetMachineGuidWStr();
    std::wstring sid  = GetUserSidWStr();

    std::wstring fp = mg + L"|" + sid + L"|" + pc;
    uint64_t leak = Fnv1a64(fp.c_str());
    std::wstring leakId = Hex64(leak).substr(0, 12);

    std::wstring t = NowLocalTimeShort();

    // 4 lines
    std::wstring line1 = L"TheTrueCerts";
    if (!dumpName.empty()) line1 += L" | " + dumpName;
    if (!dumpId.empty())   line1 += L" | " + dumpId;

    std::wstring line2 = L"User: " + user + L" | PC: " + pc;

    std::wstring line3;
    bool any3 = false;
    if (!ip.empty()) { line3 += L"IP: " + ip; any3 = true; }
    if (!mg.empty()) { if (any3) line3 += L" | "; line3 += L"MID: " + Tail(mg, 8); any3 = true; }
    if (!sid.empty()) { if (any3) line3 += L" | "; line3 += L"SID: " + Tail(sid, 8); any3 = true; }
    if (!any3) line3 = L"Device Verified";

    std::wstring line4 = L"LeakID: " + leakId + L" | " + t;
    if (!userTag.empty()) line4 += L" | " + userTag;
    if (!orderId.empty()) line4 += L" | " + orderId;

    return line1 + L"\n" + line2 + L"\n" + line3 + L"\n" + line4;
}

// ---------------- watermark tile cache ----------------
struct TileCache {
    std::wstring text;
    int w = 0;
    int h = 0;
    Gdiplus::Bitmap* bmp = nullptr;

    void Clear() {
        if (bmp) { delete bmp; bmp = nullptr; }
        text.clear();
        w = h = 0;
    }
};

static TileCache g_tile;

static void BuildTileIfNeeded(const std::wstring& text) {
    if (text.empty()) return;
    if (g_tile.bmp && g_tile.text == text) return;

    g_tile.Clear();
    g_tile.text = text;

    // Opacity kept exactly as-is:
    const BYTE  alpha   = 26;

    // Smaller font (so more tiles fit on page)
    const float fontPx  = 16.0f;

    const float angle   = -32.0f;
    const wchar_t* fontName = L"Segoe UI";

    Gdiplus::Bitmap tmp(1, 1, PixelFormat32bppPARGB);
    Gdiplus::Graphics g0(&tmp);
    g0.SetTextRenderingHint(Gdiplus::TextRenderingHintAntiAliasGridFit);

    Gdiplus::FontFamily ff(fontName);
    Gdiplus::Font font(&ff, fontPx, Gdiplus::FontStyleBold, Gdiplus::UnitPixel);

    Gdiplus::StringFormat fmt;
    fmt.SetAlignment(Gdiplus::StringAlignmentCenter);
    fmt.SetLineAlignment(Gdiplus::StringAlignmentCenter);
    fmt.SetTrimming(Gdiplus::StringTrimmingNone);

    Gdiplus::RectF bigLayout(0, 0, 2000.0f, 2000.0f);
    Gdiplus::RectF bounds;
    g0.MeasureString(text.c_str(), -1, &font, bigLayout, &fmt, &bounds);

    // Smaller tile padding -> repeats more frequently
    int tileW = (int)bounds.Width  + 180;
    int tileH = (int)bounds.Height + 140;

    // Lower minimums -> more repetitions
    if (tileW < 380) tileW = 380;
    if (tileH < 280) tileH = 280;

    g_tile.w = tileW;
    g_tile.h = tileH;

    g_tile.bmp = new Gdiplus::Bitmap(tileW, tileH, PixelFormat32bppPARGB);
    Gdiplus::Graphics g(g_tile.bmp);
    g.SetSmoothingMode(Gdiplus::SmoothingModeHighQuality);
    g.SetTextRenderingHint(Gdiplus::TextRenderingHintAntiAliasGridFit);
    g.Clear(Gdiplus::Color(0, 0, 0, 0));

    g.TranslateTransform(tileW * 0.5f, tileH * 0.5f);
    g.RotateTransform(angle);

    Gdiplus::RectF layout(-tileW * 0.5f, -tileH * 0.5f,
                          (Gdiplus::REAL)tileW, (Gdiplus::REAL)tileH);

    Gdiplus::SolidBrush brush(Gdiplus::Color(alpha, 255, 0, 0));
    g.DrawString(text.c_str(), -1, &font, layout, &fmt, &brush);
}

// ---------------- public: draw watermark ----------------
void DrawWatermark(HDC hdc, const RECT& rc, const std::wstring& text) {
    DrawWatermark(hdc, rc, text, 0);
}

void DrawWatermark(HDC hdc, const RECT& rc, const std::wstring& text, int scrollY) {
    if (!hdc || text.empty()) return;

    EnsureGdiPlus();
    BuildTileIfNeeded(text);
    if (!g_tile.bmp || g_tile.h <= 0) return;

    const int W = rc.right - rc.left;
    const int H = rc.bottom - rc.top;
    if (W <= 0 || H <= 0) return;

    int phaseY = scrollY % g_tile.h;
    if (phaseY < 0) phaseY += g_tile.h;

    Gdiplus::Graphics g(hdc);
    g.SetSmoothingMode(Gdiplus::SmoothingModeHighQuality);
    g.SetCompositingMode(Gdiplus::CompositingModeSourceOver);

    Gdiplus::TextureBrush tb(g_tile.bmp, Gdiplus::WrapModeTile);
    tb.ResetTransform();
    tb.TranslateTransform((Gdiplus::REAL)rc.left, (Gdiplus::REAL)(rc.top - phaseY));

    g.FillRectangle(&tb,
        (Gdiplus::REAL)rc.left, (Gdiplus::REAL)rc.top,
        (Gdiplus::REAL)W, (Gdiplus::REAL)H
    );
}

} // namespace ttc::reader
