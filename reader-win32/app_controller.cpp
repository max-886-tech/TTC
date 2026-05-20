#ifndef NOMINMAX
#define NOMINMAX
#endif

#include <windows.h>
#include "app_controller.h"
#include <commctrl.h>
#pragma comment(lib, "Comctl32.lib")
#pragma comment(lib, "Msimg32.lib")
#include <commdlg.h>
#include <shellscalingapi.h>
#include <shellapi.h>   // CommandLineToArgvW
#include <uxtheme.h>
#pragma comment(lib, "UxTheme.lib")
#include <shlobj.h>     // SHCreateDirectoryExW, SHGetKnownFolderPath
#include <string>
#include <vector>
#include <memory>
#include <utility> // std::move
#include <chrono>
#include <cstdint>
#include <utility>

#include <wtsapi32.h>
#pragma comment(lib, "Wtsapi32.lib")

#pragma comment(lib, "Ole32.lib")

#ifndef WDA_EXCLUDEFROMCAPTURE
#define WDA_EXCLUDEFROMCAPTURE 0x00000011
#endif

#pragma comment(lib, "Shcore.lib")

#include "../core/ttc_format.h"
#include "../core/ttc_decrypt.h"
#include "watermark.h"
#include "pdf_viewer.h"
#include "protection.h"
#include "ui_common.h"
#include "ui_access.h"
#include "ui_download.h"
#include "ui_ids.h"

// NEW: online license/password validation
#include "license_online.h"

using namespace ttc::format;

// ---------------------------
// Icon resource ID (MUST match app.rc)
// ---------------------------
#ifndef IDI_APPICON
#define IDI_APPICON 101
#endif

static void SetWindowIcons(HWND hwnd, HINSTANCE hInst) {
    HICON hBig = (HICON)LoadImageW(hInst, MAKEINTRESOURCEW(IDI_APPICON),
                                   IMAGE_ICON, 32, 32, LR_DEFAULTCOLOR);
    HICON hSmall = (HICON)LoadImageW(hInst, MAKEINTRESOURCEW(IDI_APPICON),
                                     IMAGE_ICON, 16, 16, LR_DEFAULTCOLOR);

    if (hBig)   SendMessageW(hwnd, WM_SETICON, ICON_BIG,   (LPARAM)hBig);
    if (hSmall) SendMessageW(hwnd, WM_SETICON, ICON_SMALL, (LPARAM)hSmall);
}


// ---------------------------
// DPI helpers + rounded rect drawing
// ---------------------------
static UINT GetWindowDpi(HWND hwnd) {
    // Prefer GetDpiForWindow (Win10+). Fallback to 96.
    HMODULE hUser = GetModuleHandleW(L"user32.dll");
    if (hUser) {
        using GetDpiForWindow_t = UINT(WINAPI*)(HWND);
        auto p = (GetDpiForWindow_t)GetProcAddress(hUser, "GetDpiForWindow");
        if (p) return p(hwnd);
    }
    return 96;
}

static int DpiScale(HWND hwnd, int px) {
    UINT dpi = GetWindowDpi(hwnd);
    return (int)MulDiv(px, (int)dpi, 96);
}

// Draw a filled rounded rectangle with optional border
static void DrawRoundRect(HDC hdc, const RECT& rc, int radiusPx, HBRUSH fillBrush, HPEN borderPen) {
    HGDIOBJ oldBrush = SelectObject(hdc, fillBrush ? (HGDIOBJ)fillBrush : GetStockObject(NULL_BRUSH));
    HGDIOBJ oldPen   = SelectObject(hdc, borderPen ? (HGDIOBJ)borderPen : GetStockObject(NULL_PEN));

    int r = radiusPx;
    if (r < 0) r = 0;
    RoundRect(hdc, rc.left, rc.top, rc.right, rc.bottom, r * 2, r * 2);

    SelectObject(hdc, oldPen);
    SelectObject(hdc, oldBrush);
}


// UI IDs
static constexpr int IDC_BTN_PREV    = 1101;
static constexpr int IDC_BTN_NEXT    = 1102;
static constexpr int IDC_BTN_FITW    = 1103;
static constexpr int IDC_BTN_FITP    = 1104;
static constexpr int IDC_BTN_ZOOMOUT = 1105;
static constexpr int IDC_BTN_ZOOMIN  = 1106;

// toggle thumbs button
static constexpr int IDC_BTN_THUMBS  = 1107;

static constexpr int IDC_CMB_ZOOM    = 1201;
static constexpr int IDC_STATUS      = 1301;

// Ctrl+T hotkey ID
static constexpr int HK_TOGGLE_THUMBS = 1;

static PdfViewer g_viewer;
static std::vector<uint8_t> g_pdf;
static std::wstring g_watermarkText;

// If we auto-download an .ttc, keep a delete-on-close handle open until exit.
static HANDLE g_ttcDeleteHandle = INVALID_HANDLE_VALUE;
static std::wstring g_sessionDir;

static HWND g_hToolbar = nullptr;
static HWND g_hStatus  = nullptr;
static HWND g_hZoomCmb = nullptr;
static HWND g_hBtnThumbs = nullptr;

enum class UiMode { EnterCode, Downloading, Viewer };
static UiMode g_mode = UiMode::EnterCode;
// Result produced by the worker thread (posted back to UI thread)
struct AsyncResult {
    std::vector<uint8_t> pdf;
    std::wstring watermark;
};

// Forward declarations
static void ShowMode(HWND hwnd, UiMode m);
static void StatusUpdate(HWND hwnd);

static void ApplyStartupFonts(HWND hwnd) {
    // After WM_DPICHANGED we recreate fonts; re-apply them to already-created controls.
    HWND pStart = GetDlgItem(hwnd, IDC_START_PANEL);
    if (pStart) {
        if (HWND h = GetDlgItem(pStart, IDC_LBL_TITLE))    SendMessageW(h, WM_SETFONT, (WPARAM)UiTitleFont(), TRUE);
        if (HWND h = GetDlgItem(pStart, IDC_LBL_SUB))      SendMessageW(h, WM_SETFONT, (WPARAM)UiBodyFont(), TRUE);
        if (HWND h = GetDlgItem(pStart, IDC_CODE_EDIT))    SendMessageW(h, WM_SETFONT, (WPARAM)UiBodyFont(), TRUE);
        if (HWND h = GetDlgItem(pStart, IDC_BTN_CONTINUE)) SendMessageW(h, WM_SETFONT, (WPARAM)UiBodyFont(), TRUE);
    }

    HWND pDl = GetDlgItem(hwnd, IDC_DL_PANEL);
    if (pDl) {
        if (HWND h = GetDlgItem(pDl, IDC_DL_LABEL)) SendMessageW(h, WM_SETFONT, (WPARAM)UiBodyFont(), TRUE);
    }
}



// Optional: file passed via association. If present we skip "download" step and just open it after validation.
static std::wstring g_initialTtcPath;

static void ShowMode(HWND hwnd, UiMode m) {
    // Remote-session behavior (B): allow access/download, but block viewer.
    if (m == UiMode::Viewer && IsRemoteSession()) {
        MessageBoxW(hwnd,
            L"For security, viewing is disabled in Remote Desktop / remote sessions.\n\n"
            L"Please open TheTrueCerts Reader on your local desktop.",
            L"TheTrueCerts Reader", MB_ICONWARNING | MB_OK);
        m = UiMode::EnterCode;
    }

    g_mode = m;

    bool viewer = (m == UiMode::Viewer);
    bool enter  = (m == UiMode::EnterCode);
    bool dl     = (m == UiMode::Downloading);

    // Capture protection ONLY for viewer
    ApplyCaptureProtection(hwnd, viewer);

    // Toolbar/status/viewer children
    if (g_hToolbar) ShowWindow(g_hToolbar, viewer ? SW_SHOW : SW_HIDE);
    if (g_hStatus)  ShowWindow(g_hStatus,  viewer ? SW_SHOW : SW_HIDE);

    if (g_viewer.GetViewHwnd())   ShowWindow(g_viewer.GetViewHwnd(), viewer ? SW_SHOW : SW_HIDE);
    if (g_viewer.GetThumbsHwnd()) ShowWindow(g_viewer.GetThumbsHwnd(), (viewer && g_viewer.IsThumbsVisible()) ? SW_SHOW : SW_HIDE);

    // Startup panels (isolated modules)
    ui_access::Show(enter);
    ui_download::Show(dl);

    SendMessageW(hwnd, WM_SIZE, 0, 0);
    InvalidateRect(hwnd, nullptr, TRUE);
}
// Progress callback from downloader (worker thread)
static void DownloadProgressCb(uint64_t downloaded, uint64_t total, void* user) {
    HWND hwnd = (HWND)user;
    if (!hwnd) return;
    if (total > 0) {
        int pct = (int)((downloaded * 100ULL) / total);
        if (pct < 0) pct = 0;
        if (pct > 100) pct = 100;
        PostMessageW(hwnd, WM_APP_PROGRESS, (WPARAM)pct, 0);
    } else {
        // unknown total (chunked/no content-length)
        PostMessageW(hwnd, WM_APP_PROGRESS, (WPARAM)(downloaded / 1024ULL), (LPARAM)-1);
    }
}

// ---------------------------
// App version (semver)
// ---------------------------
static const char* APP_VERSION = "1.0.1";

static std::string NormalizeSemver(std::string v) {
    // Strip leading/trailing spaces and leading 'v'
    auto isSpace = [](unsigned char c){ return std::isspace(c) != 0; };
    while (!v.empty() && isSpace((unsigned char)v.front())) v.erase(v.begin());
    while (!v.empty() && isSpace((unsigned char)v.back())) v.pop_back();
    if (!v.empty() && (v[0] == 'v' || v[0] == 'V')) v.erase(v.begin());
    // Keep only digits and dots (stop at first invalid)
    std::string out;
    for (char c : v) {
        if ((c >= '0' && c <= '9') || c == '.') out.push_back(c);
        else break;
    }
    return out.empty() ? std::string("0.0.0") : out;
}

static void ParseSemver(const std::string& v, int& a, int& b, int& c) {
    a = b = c = 0;
    std::string s = NormalizeSemver(v);
    int parts[3]{0,0,0};
    int pi = 0;
    int cur = 0;
    bool has = false;
    for (size_t i=0;i<=s.size();i++){
        char ch = (i<s.size()? s[i] : '.');
        if (ch >= '0' && ch <= '9') { cur = cur*10 + (ch-'0'); has = true; }
        else if (ch == '.') {
            if (pi < 3) parts[pi++] = has ? cur : 0;
            cur = 0; has = false;
        } else break;
    }
    a = parts[0]; b = parts[1]; c = parts[2];
}

// returns -1 if a<b, 0 if equal, +1 if a>b
static int CmpSemver(const std::string& a, const std::string& b) {
    int a1,a2,a3,b1,b2,b3;
    ParseSemver(a,a1,a2,a3);
    ParseSemver(b,b1,b2,b3);
    if (a1!=b1) return a1<b1?-1:1;
    if (a2!=b2) return a2<b2?-1:1;
    if (a3!=b3) return a3<b3?-1:1;
    return 0;
}

static std::wstring Utf8ToWide(const std::string& s);

static void MaybeEnforceUpdate(const ttc::reader::license::LicenseInfo& lic) {
    const std::string cur = APP_VERSION;
    const std::string minv = lic.min_version.empty() ? "" : lic.min_version;
    const std::string latest = lic.latest_version.empty() ? "" : lic.latest_version;

    auto openUpdateUrl = [&]() {
        if (!lic.update_url.empty()) {
            std::wstring urlW = Utf8ToWide(lic.update_url);
            ShellExecuteW(nullptr, L"open", urlW.c_str(), nullptr, nullptr, SW_SHOWNORMAL);
        }
    };

    // Force update rule:
    // - If server says force_update=true OR min_version is present,
    //   and app < min_version -> block.
    if (!minv.empty() && CmpSemver(cur, minv) < 0) {
        std::wstring msg = L"Your reader is outdated.\n\nCurrent: " + Utf8ToWide(cur) +
                           L"\nRequired: " + Utf8ToWide(minv) +
                           L"\n\nPlease update to continue.";
        MessageBoxW(nullptr, msg.c_str(), L"Update Required", MB_OK | MB_ICONWARNING);
        openUpdateUrl();
        ExitProcess(0);
    }
    if (lic.force_update && !latest.empty() && CmpSemver(cur, latest) < 0) {
        std::wstring msg = L"A new version of the reader is available and required.\n\nCurrent: " + Utf8ToWide(cur) +
                           L"\nLatest: " + Utf8ToWide(latest) +
                           L"\n\nPlease update to continue.";
        MessageBoxW(nullptr, msg.c_str(), L"Update Required", MB_OK | MB_ICONWARNING);
        openUpdateUrl();
        ExitProcess(0);
    }

    // Soft update notice:
    if (!latest.empty() && CmpSemver(cur, latest) < 0) {
        std::wstring msg = L"A new reader update is available.\n\nCurrent: " + Utf8ToWide(cur) +
                           L"\nLatest: " + Utf8ToWide(latest) +
                           L"\n\nDo you want to open the update page now?";
        if (MessageBoxW(nullptr, msg.c_str(), L"Update Available", MB_YESNO | MB_ICONINFORMATION) == IDYES) {
            openUpdateUrl();
        }
    }
}


static bool IsRemoteSessionRdp()
{
    void* buf = nullptr;
    DWORD bytes = 0;

    if (WTSQuerySessionInformationW(
            WTS_CURRENT_SERVER_HANDLE,
            WTS_CURRENT_SESSION,
            WTSClientProtocolType,
            (LPWSTR*)&buf,
            &bytes) && buf && bytes >= sizeof(USHORT))
    {
        USHORT proto = *(USHORT*)buf;
        WTSFreeMemory(buf);
        return proto != 0; // 0=console, 2=RDP
    }
    return GetSystemMetrics(SM_REMOTESESSION) != 0;
}

static void EnableDpiAwareness() {
    // Best (Win10+): Per-monitor V2 scaling so UI looks correct on 13" and 27"
    HMODULE user32 = LoadLibraryW(L"user32.dll");
    if (user32) {
        auto fn = (BOOL(WINAPI*)(DPI_AWARENESS_CONTEXT))GetProcAddress(user32, "SetProcessDpiAwarenessContext");
        if (fn) {
            fn(DPI_AWARENESS_CONTEXT_PER_MONITOR_AWARE_V2);
            FreeLibrary(user32);
            return;
        }
        FreeLibrary(user32);
    }
    // Fallback
    SetProcessDpiAwareness(PROCESS_PER_MONITOR_DPI_AWARE);
}


// ---------------------------
// Toolbar owner-draw buttons (modern look)
// ---------------------------
struct TbBtnState {
    int id = 0;
    bool hot = false;
    bool down = false;
    bool icon = false; // icon-style button (single char)
    std::wstring text;
    std::wstring tip;
};

static std::vector<TbBtnState> g_tbBtns;
static HWND g_hTooltip = nullptr;
static HFONT g_fontUi = nullptr;
static HFONT g_fontIcon = nullptr;

static TbBtnState* TbFind(int id) {
    for (auto& b : g_tbBtns) if (b.id == id) return &b;
    return nullptr;
}

static void TbInvalidateBtn(int id) {
    HWND h = GetDlgItem(GetParent(g_hToolbar), id);
    if (h) InvalidateRect(h, nullptr, TRUE);
}

static HFONT MakeIconFont(HWND hwnd, int pt) {
    int dpi = (int)GetWindowDpi(hwnd);
    int px = -MulDiv(pt, dpi, 72);

    // Prefer Fluent (Win11), fallback to MDL2 (Win10), fallback Symbol
    const wchar_t* fonts[] = { L"Segoe Fluent Icons", L"Segoe MDL2 Assets", L"Segoe UI Symbol" };
    for (auto f : fonts) {
        HFONT h = CreateFontW(px, 0, 0, 0, FW_SEMIBOLD, FALSE, FALSE, FALSE,
            DEFAULT_CHARSET, OUT_DEFAULT_PRECIS, CLIP_DEFAULT_PRECIS, CLEARTYPE_QUALITY,
            DEFAULT_PITCH | FF_SWISS, f);
        if (h) return h;
    }
    return nullptr;
}

static void TbEnsureFonts(HWND hwnd) {
    if (g_fontUi && g_fontIcon) return;
    int dpi = (int)GetWindowDpi(hwnd);

    int uiPx   = -MulDiv(10, dpi, 72); // 10pt
    int iconPt = 14;                   // slightly larger for icon-only toolbar

    g_fontUi = CreateFontW(uiPx, 0, 0, 0, FW_SEMIBOLD, FALSE, FALSE, FALSE,
        DEFAULT_CHARSET, OUT_DEFAULT_PRECIS, CLIP_DEFAULT_PRECIS, CLEARTYPE_QUALITY,
        DEFAULT_PITCH | FF_SWISS, L"Segoe UI");

    g_fontIcon = MakeIconFont(hwnd, iconPt);
    if (!g_fontIcon) {
        g_fontIcon = CreateFontW(-MulDiv(12, dpi, 72), 0, 0, 0, FW_SEMIBOLD, FALSE, FALSE, FALSE,
            DEFAULT_CHARSET, OUT_DEFAULT_PRECIS, CLIP_DEFAULT_PRECIS, CLEARTYPE_QUALITY,
            DEFAULT_PITCH | FF_SWISS, L"Segoe UI Symbol");
    }
}

static LRESULT CALLBACK TbBtnSubclassProc(HWND h, UINT m, WPARAM w, LPARAM l, UINT_PTR, DWORD_PTR) {
    int id = (int)(INT_PTR)GetWindowLongPtrW(h, GWLP_ID);
    TbBtnState* st = TbFind(id);

    switch (m) {
    case WM_MOUSEMOVE:
        if (st && !st->hot) {
            st->hot = true;
            TbInvalidateBtn(id);
            TRACKMOUSEEVENT tme{ sizeof(tme), TME_LEAVE, h, 0 };
            TrackMouseEvent(&tme);
        }
        break;
    case WM_MOUSELEAVE:
        if (st && st->hot) { st->hot = false; st->down = false; TbInvalidateBtn(id); }
        break;
    case WM_LBUTTONDOWN:
        if (st) { st->down = true; TbInvalidateBtn(id); }
        break;
    case WM_LBUTTONUP:
        if (st) { st->down = false; TbInvalidateBtn(id); }
        break;
    }
    return DefSubclassProc(h, m, w, l);
}

static void TbEnsureTooltip(HWND hwnd) {
    if (g_hTooltip) return;
    g_hTooltip = CreateWindowExW(0, TOOLTIPS_CLASSW, nullptr,
        WS_POPUP | TTS_ALWAYSTIP | TTS_NOPREFIX,
        CW_USEDEFAULT, CW_USEDEFAULT, CW_USEDEFAULT, CW_USEDEFAULT,
        hwnd, nullptr, (HINSTANCE)GetModuleHandleW(nullptr), nullptr);

    SetWindowPos(g_hTooltip, HWND_TOPMOST, 0, 0, 0, 0,
        SWP_NOMOVE | SWP_NOSIZE | SWP_NOACTIVATE);
}

static void TbAddToolTip(HWND parent, HWND hCtrl, const std::wstring& text) {
    if (text.empty()) return;
    TbEnsureTooltip(parent);

    TOOLINFOW ti{};
    ti.cbSize = sizeof(ti);
    ti.uFlags = TTF_IDISHWND | TTF_SUBCLASS;
    ti.hwnd = parent;
    ti.uId = (UINT_PTR)hCtrl;
    ti.lpszText = (LPWSTR)text.c_str();
    SendMessageW(g_hTooltip, TTM_ADDTOOLW, 0, (LPARAM)&ti);
}



static void TbDrawButton(const DRAWITEMSTRUCT* dis) {
    int id = (int)dis->CtlID;
    TbBtnState* st = TbFind(id);
    if (!st) return;

    HDC hdc = dis->hDC;
    RECT rc = dis->rcItem;

    bool disabled = (dis->itemState & ODS_DISABLED) != 0;
    bool pressed  = (dis->itemState & ODS_SELECTED) != 0 || st->down;

    // colors (subtle, modern-ish)
    COLORREF bg     = GetSysColor(COLOR_WINDOW);
    COLORREF border = GetSysColor(COLOR_3DSHADOW);
    COLORREF hover  = GetSysColor(COLOR_HIGHLIGHT);
    COLORREF txt    = GetSysColor(COLOR_WINDOWTEXT);

    // soften hover by mixing
    auto mix = [](COLORREF a, COLORREF b, int t /*0..100*/) -> COLORREF {
        int ar = GetRValue(a), ag = GetGValue(a), ab = GetBValue(a);
        int br = GetRValue(b), bg = GetGValue(b), bb = GetBValue(b);
        int rr = (ar*(100-t) + br*t)/100;
        int rg = (ag*(100-t) + bg*t)/100;
        int rb = (ab*(100-t) + bb*t)/100;
        return RGB(rr,rg,rb);
    };

    COLORREF fill = bg;
    if (st->hot) fill = mix(bg, hover, 12);
    if (pressed) fill = mix(bg, hover, 22);
    if (disabled) { fill = mix(bg, border, 10); txt = mix(txt, bg, 55); border = mix(border, bg, 40); }

    // Modern look: no visible border unless hovered/pressed/disabled
    if (!st->hot && !pressed && !disabled) border = fill;

    HBRUSH brFill = CreateSolidBrush(fill);
    HPEN penBr = CreatePen(PS_SOLID, 1, border);

    // slight inset when pressed
    if (pressed) { rc.left++; rc.top++; }

    SetBkMode(hdc, TRANSPARENT);
    DrawRoundRect(hdc, rc, 8, brFill, penBr);

    DeleteObject(brFill);
    DeleteObject(penBr);

    // text
    std::wstring label = st->text;
    HFONT font = st->icon ? g_fontIcon : g_fontUi;
    HGDIOBJ oldFont = SelectObject(hdc, font);
    SetTextColor(hdc, txt);

    RECT trc = rc;
    InflateRect(&trc, -6, -2);
    DrawTextW(hdc, label.c_str(), (int)label.size(), &trc, DT_CENTER | DT_VCENTER | DT_SINGLELINE);

    SelectObject(hdc, oldFont);

    if (dis->itemState & ODS_FOCUS) {
        RECT frc = rc;
        InflateRect(&frc, -3, -3);
        DrawFocusRect(hdc, &frc);
    }
}

static std::wstring OpenTtcDialog(HWND hwnd) {
    wchar_t file[MAX_PATH] = L"";
    OPENFILENAMEW ofn{};
    ofn.lStructSize = sizeof(ofn);
    ofn.hwndOwner = hwnd;
    ofn.lpstrFilter = L"TTC Dumps Files (*.ttc)\0*.ttc\0";
    ofn.lpstrFile = file;
    ofn.nMaxFile = MAX_PATH;
    ofn.Flags = OFN_FILEMUSTEXIST;
    if (!GetOpenFileNameW(&ofn)) return L"";
    return file;
}

static std::string WideToAcp(const std::wstring& ws) {
    if (ws.empty()) return {};
    int len = WideCharToMultiByte(CP_ACP, 0, ws.c_str(), (int)ws.size(), nullptr, 0, nullptr, nullptr);
    std::string out(len, '\0');
    WideCharToMultiByte(CP_ACP, 0, ws.c_str(), (int)ws.size(), out.data(), len, nullptr, nullptr);
    return out;
}

static std::string WideToUtf8(const std::wstring& ws) {
    if (ws.empty()) return {};
    int len = WideCharToMultiByte(CP_UTF8, 0, ws.c_str(), (int)ws.size(), nullptr, 0, nullptr, nullptr);
    std::string out(len, '\0');
    WideCharToMultiByte(CP_UTF8, 0, ws.c_str(), (int)ws.size(), out.data(), len, nullptr, nullptr);
    return out;
}

static std::wstring Utf8ToWide(const std::string& s) {
    if (s.empty()) return L"";
    int n = MultiByteToWideChar(CP_UTF8, 0, s.data(), (int)s.size(), nullptr, 0);
    std::wstring w(n, L'\0');
    MultiByteToWideChar(CP_UTF8, 0, s.data(), (int)s.size(), w.data(), n);
    return w;
}

// ---------------------------
// Download path helpers
// ---------------------------
static bool EnsureDir(const std::wstring& dir) {
    int r = SHCreateDirectoryExW(NULL, dir.c_str(), NULL);
    return (r == ERROR_SUCCESS || r == ERROR_FILE_EXISTS || r == ERROR_ALREADY_EXISTS);
}

static bool DeleteTreeBestEffort(const std::wstring& dir) {
    // Best-effort recursive delete (directories + files). Intended for temp/session cleanup.
    if (dir.empty()) return false;

    std::wstring pattern = dir;
    if (!pattern.empty() && pattern.back() != L'\\') pattern += L"\\";
    pattern += L"*";

    WIN32_FIND_DATAW fd{};
    HANDLE hFind = FindFirstFileW(pattern.c_str(), &fd);
    if (hFind == INVALID_HANDLE_VALUE) {
        RemoveDirectoryW(dir.c_str());
        return true;
    }

    do {
        const wchar_t* name = fd.cFileName;
        if (!name || wcscmp(name, L".") == 0 || wcscmp(name, L"..") == 0) continue;

        std::wstring full = dir;
        if (!full.empty() && full.back() != L'\\') full += L"\\";
        full += name;

        if (fd.dwFileAttributes & FILE_ATTRIBUTE_DIRECTORY) {
            DeleteTreeBestEffort(full);
            RemoveDirectoryW(full.c_str());
        } else {
            SetFileAttributesW(full.c_str(), FILE_ATTRIBUTE_NORMAL);
            DeleteFileW(full.c_str());
        }
    } while (FindNextFileW(hFind, &fd));

    FindClose(hFind);
    RemoveDirectoryW(dir.c_str());
    return true;
}

static std::wstring GetLocalAppDataDir() {
    PWSTR p = nullptr;
    std::wstring out;
    if (SUCCEEDED(SHGetKnownFolderPath(FOLDERID_LocalAppData, 0, NULL, &p)) && p) {
        out = p;
        CoTaskMemFree(p);
    }
    return out;
}

static std::wstring GuidToWString(const GUID& g) {
    wchar_t buf[64];
    swprintf_s(buf,
               L"%08lX-%04hX-%04hX-%02hhX%02hhX-%02hhX%02hhX%02hhX%02hhX%02hhX%02hhX",
               g.Data1, g.Data2, g.Data3,
               g.Data4[0], g.Data4[1], g.Data4[2], g.Data4[3],
               g.Data4[4], g.Data4[5], g.Data4[6], g.Data4[7]);
    return buf;
}

static std::wstring GetSessionsBaseDir() {
    std::wstring lad = GetLocalAppDataDir();
    std::wstring base = lad.empty() ? L".\\thetruecerts.com\\sessions\\" : (lad + L"\\thetruecerts.com\\sessions\\");
    EnsureDir(base);
    return base;
}

static std::wstring CreateSessionDir() {
    GUID g{};
    CoCreateGuid(&g);
    std::wstring base = GetSessionsBaseDir();
    std::wstring dir = base + GuidToWString(g) + L"\\";
    EnsureDir(dir);
    // Hide session folder (cosmetic; not a security boundary)
    SetFileAttributesW(dir.c_str(), FILE_ATTRIBUTE_HIDDEN);
    return dir;
}

static uint64_t FileTimeToU64(const FILETIME& ft) {
    ULARGE_INTEGER uli{};
    uli.LowPart = ft.dwLowDateTime;
    uli.HighPart = ft.dwHighDateTime;
    return (uint64_t)uli.QuadPart;
}

static void CleanupOldSessions(const std::wstring& baseDir, int olderThanHours) {
    // Deletes session folders older than olderThanHours. Best-effort.
    if (baseDir.empty()) return;

    FILETIME nowFt{};
    GetSystemTimeAsFileTime(&nowFt);
    const uint64_t now = FileTimeToU64(nowFt);
    const uint64_t threshold = now - (uint64_t)olderThanHours * 60ULL * 60ULL * 10000000ULL; // 100ns ticks

    std::wstring pattern = baseDir;
    if (!pattern.empty() && pattern.back() != L'\\') pattern += L"\\";
    pattern += L"*";

    WIN32_FIND_DATAW fd{};
    HANDLE hFind = FindFirstFileW(pattern.c_str(), &fd);
    if (hFind == INVALID_HANDLE_VALUE) return;

    do {
        if (!(fd.dwFileAttributes & FILE_ATTRIBUTE_DIRECTORY)) continue;
        if (wcscmp(fd.cFileName, L".") == 0 || wcscmp(fd.cFileName, L"..") == 0) continue;

        const uint64_t lastWrite = FileTimeToU64(fd.ftLastWriteTime);
        if (lastWrite > 0 && lastWrite < threshold) {
            std::wstring full = baseDir;
            if (!full.empty() && full.back() != L'\\') full += L"\\";
            full += fd.cFileName;
            DeleteTreeBestEffort(full);
        }
    } while (FindNextFileW(hFind, &fd));

    FindClose(hFind);
}

static std::wstring SanitizeFilename(std::wstring name) {
    const std::wstring bad = L"\\/:*?\"<>|";
    for (auto& ch : name) {
        if (bad.find(ch) != std::wstring::npos) ch = L'_';
    }
    if (name.empty()) name = L"download.ttc";
    return name;
}

static std::wstring BasenameFromUrl(const std::wstring& url) {
    // Remove query string
    std::wstring path = url;
    size_t q = path.find(L'?');
    if (q != std::wstring::npos) path = path.substr(0, q);
    // strip trailing '/'
    while (!path.empty() && (path.back() == L'/' || path.back() == L'\\')) path.pop_back();
    size_t slash = path.find_last_of(L"/\\");
    std::wstring base = (slash == std::wstring::npos) ? path : path.substr(slash + 1);
    if (base.empty()) base = L"download.ttc";
    // ensure .ttc
    if (base.size() < 5 || _wcsicmp(base.c_str() + (base.size() - 5), L".ttc") != 0) {
        base += L".ttc";
    }
    return SanitizeFilename(base);
}

static std::wstring MakeSessionDownloadPath(const std::wstring& desiredBaseName) {
    // Per-session folder + randomized file name. Keep extension .ttc.
    (void)desiredBaseName; // name intentionally not used (avoid predictable names)
    if (g_sessionDir.empty()) g_sessionDir = CreateSessionDir();

    GUID g{};
    CoCreateGuid(&g);
    return g_sessionDir + GuidToWString(g) + L".ttc";
}

static void StatusUpdate(HWND hwnd) {
    (void)hwnd;
    if (!g_hStatus) return;
    int page = g_viewer.GetCurrentPage() + 1;
    int pages = g_viewer.GetPageCount();
    int zoom = g_viewer.GetZoomPercent();

    wchar_t buf[128];
    swprintf_s(buf, L"Page %d / %d    %d%%", page, pages, zoom);
    SetWindowTextW(g_hStatus, buf);
}

static void ZoomComboFill() {
    if (!g_hZoomCmb) return;
    SendMessageW(g_hZoomCmb, CB_RESETCONTENT, 0, 0);
    const wchar_t* items[] = { L"Fit Width", L"Fit Page", L"50%", L"75%", L"100%", L"125%", L"150%", L"200%" };
    for (auto s : items) SendMessageW(g_hZoomCmb, CB_ADDSTRING, 0, (LPARAM)s);
    SendMessageW(g_hZoomCmb, CB_SETCURSEL, 0, 0);
}

static void ZoomComboSync() {
    if (!g_hZoomCmb) return;
    if (g_viewer.GetZoomMode() == PdfViewer::ZoomMode::FitWidth) { SendMessageW(g_hZoomCmb, CB_SETCURSEL, 0, 0); return; }
    if (g_viewer.GetZoomMode() == PdfViewer::ZoomMode::FitPage)  { SendMessageW(g_hZoomCmb, CB_SETCURSEL, 1, 0); return; }

    int z = g_viewer.GetZoomPercent();
    int sel = 4; // default 100
    if (z <= 50) sel = 2;
    else if (z <= 75) sel = 3;
    else if (z <= 100) sel = 4;
    else if (z <= 125) sel = 5;
    else if (z <= 150) sel = 6;
    else sel = 7;
    SendMessageW(g_hZoomCmb, CB_SETCURSEL, sel, 0);
}

static void ThumbsButtonSync() {
    if (!g_hBtnThumbs) return;
    SetWindowTextW(g_hBtnThumbs, g_viewer.IsThumbsVisible() ? L"Thumbnails: On" : L"Thumbnails: Off");
}

// ---------------------------
// Password dialog
// ---------------------------
struct PwdDlgState {
    HWND hEdit = nullptr;
    bool finished = false;
    bool ok = false;
    std::wstring pwd;
};

static int DpiScaleSys(int px) {
    UINT dpi = 96;
    HMODULE hUser32 = GetModuleHandleW(L"user32.dll");
    if (hUser32) {
        auto pGetDpiForSystem = (UINT(WINAPI*)())GetProcAddress(hUser32, "GetDpiForSystem");
        if (pGetDpiForSystem) dpi = pGetDpiForSystem();
    }
    return MulDiv(px, (int)dpi, 96);
}

static LRESULT CALLBACK PwdWndProc(HWND hwnd, UINT msg, WPARAM w, LPARAM l) {
    auto* st = (PwdDlgState*)GetWindowLongPtrW(hwnd, GWLP_USERDATA);

    switch (msg) {
    case WM_CREATE: {
        CREATESTRUCTW* cs = (CREATESTRUCTW*)l;
        SetWindowLongPtrW(hwnd, GWLP_USERDATA, (LONG_PTR)cs->lpCreateParams);
        st = (PwdDlgState*)cs->lpCreateParams;

        HFONT f = (HFONT)GetStockObject(DEFAULT_GUI_FONT);

        CreateWindowExW(0, L"STATIC", L"Enter password to open this file:",
            WS_CHILD | WS_VISIBLE,
            16, 14, 360, 18, hwnd, nullptr, GetModuleHandleW(nullptr), nullptr);

        st->hEdit = CreateWindowExW(WS_EX_CLIENTEDGE, L"EDIT", L"",
            WS_CHILD | WS_VISIBLE | WS_TABSTOP | ES_AUTOHSCROLL | ES_PASSWORD,
            16, 38, 360, 26, hwnd, (HMENU)1001, GetModuleHandleW(nullptr), nullptr);
        SendMessageW(st->hEdit, WM_SETFONT, (WPARAM)f, TRUE);
        SendMessageW(st->hEdit, EM_SETCUEBANNER, TRUE, (LPARAM)L"Password");

        HWND hOk = CreateWindowExW(0, L"BUTTON", L"OK",
            WS_CHILD | WS_VISIBLE | WS_TABSTOP | BS_DEFPUSHBUTTON,
            222, 76, 74, 26, hwnd, (HMENU)IDOK, GetModuleHandleW(nullptr), nullptr);
        HWND hCancel = CreateWindowExW(0, L"BUTTON", L"Cancel",
            WS_CHILD | WS_VISIBLE | WS_TABSTOP,
            302, 76, 74, 26, hwnd, (HMENU)IDCANCEL, GetModuleHandleW(nullptr), nullptr);

        SendMessageW(hOk, WM_SETFONT, (WPARAM)f, TRUE);
        SendMessageW(hCancel, WM_SETFONT, (WPARAM)f, TRUE);

        SetFocus(st->hEdit);
        return 0;
    }

    case WM_COMMAND: {
        int id = LOWORD(w);
        if (!st) break;

        if (id == IDOK) {
            wchar_t buf[512]{};
            GetWindowTextW(st->hEdit, buf, (int)_countof(buf));
            st->pwd = buf;

            if (st->pwd.empty()) {
                MessageBoxW(hwnd, L"Please enter password.", L"Required", MB_OK | MB_ICONWARNING);
                SetFocus(st->hEdit);
                return 0;
            }

            st->ok = true;
            st->finished = true;
            DestroyWindow(hwnd);
            return 0;
        }
        if (id == IDCANCEL) {
            st->ok = false;
            st->finished = true;
            DestroyWindow(hwnd);
            return 0;
        }
        break;
    }
    case WM_CLOSE:
        if (st) {
            st->ok = false;
            st->finished = true;
        }
        DestroyWindow(hwnd);
        return 0;
    }
    return DefWindowProcW(hwnd, msg, w, l);
}

static bool PromptPassword(std::wstring& outPwd) {
    outPwd.clear();

    static bool registered = false;
    if (!registered) {
        WNDCLASSEXW wc{};
        wc.cbSize = sizeof(wc);
        wc.lpfnWndProc = PwdWndProc;
        wc.hInstance = GetModuleHandleW(nullptr);
        wc.lpszClassName = L"TTC_PWD_DLG";
        wc.hCursor = LoadCursor(nullptr, IDC_ARROW);
        wc.hbrBackground = (HBRUSH)(COLOR_WINDOW + 1);
        wc.hIcon = (HICON)LoadImageW(wc.hInstance, MAKEINTRESOURCEW(IDI_APPICON),
                                     IMAGE_ICON, 32, 32, LR_DEFAULTCOLOR);
        wc.hIconSm = (HICON)LoadImageW(wc.hInstance, MAKEINTRESOURCEW(IDI_APPICON),
                                       IMAGE_ICON, 16, 16, LR_DEFAULTCOLOR);
        RegisterClassExW(&wc);
        registered = true;
    }

    PwdDlgState st;

    int w = DpiScaleSys(420);
    int h = DpiScaleSys(140);

    int sx = (GetSystemMetrics(SM_CXSCREEN) - w) / 2;
    int sy = (GetSystemMetrics(SM_CYSCREEN) - h) / 2;

    HWND hwnd = CreateWindowExW(
        WS_EX_DLGMODALFRAME,
        L"TTC_PWD_DLG",
        L"Enter Password",
        WS_CAPTION | WS_SYSMENU | WS_POPUP,
        sx, sy, w, h,
        nullptr, nullptr, GetModuleHandleW(nullptr),
        &st
    );

    if (!hwnd) return false;

    // Ensure dialog icon shows too
    SetWindowIcons(hwnd, GetModuleHandleW(nullptr));

    ShowWindow(hwnd, SW_SHOW);
    UpdateWindow(hwnd);

    MSG msg{};
    while (!st.finished && GetMessageW(&msg, nullptr, 0, 0)) {
        TranslateMessage(&msg);
        DispatchMessageW(&msg);
    }

    if (!st.ok) return false;
    outPwd = st.pwd;
    return true;
}

static std::string GetDeviceIdUtf8() {
    wchar_t name[256]{};
    DWORD n = (DWORD)_countof(name);
    GetComputerNameW(name, &n);

    DWORD serial = 0;
    wchar_t sysDir[MAX_PATH]{};
    GetWindowsDirectoryW(sysDir, MAX_PATH);
    wchar_t root[4] = { sysDir[0], L':', L'\\', L'\0' };
    GetVolumeInformationW(root, nullptr, 0, &serial, nullptr, nullptr, nullptr, 0);

    wchar_t buf[512]{};
    swprintf_s(buf, L"%s-%08X", name, (unsigned int)serial);
    return WideToUtf8(buf);
}

// ---------------------------
// Main window proc
// ---------------------------
LRESULT CALLBACK WndProc(HWND hwnd, UINT msg, WPARAM w, LPARAM l) {
    switch (msg) {

    case WM_APP + 210: { // receive delete-on-close handle from worker
        HANDLE hDel = (HANDLE)w;
        if (hDel && hDel != INVALID_HANDLE_VALUE) {
            if (g_ttcDeleteHandle != INVALID_HANDLE_VALUE) CloseHandle(g_ttcDeleteHandle);
            g_ttcDeleteHandle = hDel;
        }
        return 0;
    }

    case WM_APP_PROGRESS:
        if (g_mode == UiMode::Downloading) {
            return ui_download::HandleProgress(hwnd, w, l);
        }
        return 0;

    case WM_APP_BEGIN_DOWNLOAD:
        // Switch UI to downloading just before we start receiving progress updates.
        ShowMode(hwnd, UiMode::Downloading);
        return 0;

    case WM_APP_ASYNC_FAIL: {
        std::unique_ptr<std::wstring> err((std::wstring*)l);
        std::wstring msg = (err && !err->empty()) ? *err : L"Failed to download/open file.";
        // UX: If validation fails (e.g., device_mismatch), do not leave the
        // Downloading UI visible behind the error dialog. Switch back first.
        ShowMode(hwnd, UiMode::EnterCode);
        ui_access::SetNextEnabled(true);
        ui_access::FocusCode();

        MessageBoxW(hwnd, msg.c_str(), L"Error", MB_OK | MB_ICONERROR);
        return 0;
    }

    case WM_APP_ASYNC_OK: {
        std::unique_ptr<AsyncResult> res((AsyncResult*)l);

        g_watermarkText = res->watermark;
        g_viewer.SetWatermarkText(g_watermarkText);
        g_pdf = std::move(res->pdf);

        if (!g_viewer.LoadFromMemory(g_pdf)) {
            MessageBoxW(hwnd, L"Failed to open PDF in viewer.", L"Error", MB_OK | MB_ICONERROR);
            ShowMode(hwnd, UiMode::EnterCode);
            ui_access::SetNextEnabled(true);
            ui_access::FocusCode();
            return 0;
        }

        ShowMode(hwnd, UiMode::Viewer);
        StatusUpdate(hwnd);
        return 0;
    }

    case WM_CREATE: {
        // Viewer chrome
        int topH = DpiScale(hwnd, 40);
        int btnH = DpiScale(hwnd, 28);
        int pad  = DpiScale(hwnd, 6);

        g_hToolbar = CreateWindowExW(0, L"STATIC", L"", WS_CHILD,
            0, 0, 10, topH, hwnd, (HMENU)(INT_PTR)0, (HINSTANCE)GetModuleHandleW(nullptr), nullptr);

        // Owner-draw toolbar buttons are parented to this container, so WM_DRAWITEM/WM_COMMAND
        // would normally go to the toolbar (STATIC) window proc. Forward them to the main WndProc.
        SetWindowSubclass(g_hToolbar, UiPanelForwardSubclassProc, 2, 0);

        TbEnsureFonts(hwnd);

        auto mkBtn = [&](int id, const wchar_t* text, const wchar_t* tip, int x, int wbtnPx = 64, bool icon=false) -> HWND {
            HWND h = CreateWindowExW(0, L"BUTTON", text,
                WS_CHILD | WS_VISIBLE | WS_TABSTOP | BS_OWNERDRAW,
                x, pad, DpiScale(hwnd, wbtnPx), btnH, g_hToolbar, (HMENU)(INT_PTR)id,
                (HINSTANCE)GetModuleHandleW(nullptr), nullptr);

            SendMessageW(h, WM_SETFONT, (WPARAM)(icon ? g_fontIcon : g_fontUi), TRUE);
            SetWindowTheme(h, L"Explorer", nullptr);
            SetWindowSubclass(h, TbBtnSubclassProc, 1, 0);

            TbBtnState st;
            st.id = id;
            st.icon = icon;
            st.text = text ? text : L"";
            st.tip  = tip ? tip : L"";
            g_tbBtns.push_back(st);

            TbAddToolTip(hwnd, h, st.tip);
            return h;
        };

        int x = pad;
        // Icon-only modern toolbar (Fluent/MDL2). Tooltips preserve meaning.
        mkBtn(IDC_BTN_PREV,    L"\xE72B", L"Previous page", x, 42, true); x += DpiScale(hwnd, 48); // Back
        mkBtn(IDC_BTN_NEXT,    L"\xE72A", L"Next page",     x, 42, true); x += DpiScale(hwnd, 48); // Forward
        mkBtn(IDC_BTN_ZOOMOUT, L"\xE71F", L"Zoom out",      x, 42, true); x += DpiScale(hwnd, 48); // Zoom out
        mkBtn(IDC_BTN_ZOOMIN,  L"\xE8A3", L"Zoom in",       x, 42, true); x += DpiScale(hwnd, 48); // Zoom in

        mkBtn(IDC_BTN_FITW,    L"\xE91B", L"Fit to width",  x, 42, true); x += DpiScale(hwnd, 48);
        mkBtn(IDC_BTN_FITP,    L"\xE91C", L"Fit to page",   x, 42, true); x += DpiScale(hwnd, 48);

        g_hBtnThumbs = mkBtn(IDC_BTN_THUMBS, L"\xE8A7", L"Show / hide thumbnails (Ctrl+T)", x, 42, true);
        x += DpiScale(hwnd, 48);

        g_hZoomCmb = CreateWindowExW(0, L"COMBOBOX", L"",
            WS_CHILD | WS_VISIBLE | CBS_DROPDOWNLIST | WS_VSCROLL,
            x + pad, pad, DpiScale(hwnd, 140), btnH + DpiScale(hwnd, 120),
            g_hToolbar, (HMENU)(INT_PTR)IDC_CMB_ZOOM, (HINSTANCE)GetModuleHandleW(nullptr), nullptr);
        SendMessageW(g_hZoomCmb, WM_SETFONT, (WPARAM)g_fontUi, TRUE);
        SetWindowTheme(g_hZoomCmb, L"Explorer", nullptr);
        ZoomComboFill();

        int statusH = DpiScale(hwnd, 24);
        g_hStatus = CreateWindowExW(0, L"STATIC", L"", WS_CHILD | SS_LEFT,
            0, 0, 10, statusH, hwnd, (HMENU)(INT_PTR)IDC_STATUS, (HINSTANCE)GetModuleHandleW(nullptr), nullptr);
        SendMessageW(g_hStatus, WM_SETFONT, (WPARAM)g_fontUi, TRUE);

        g_viewer.CreateChildWindows(hwnd);
        RegisterHotKey(hwnd, HK_TOGGLE_THUMBS, MOD_CONTROL, 'T');

        // Startup screens (isolated modules)
        ui_access::Create(hwnd);
        ui_download::Create(hwnd);
        ThumbsButtonSync();
        ShowMode(hwnd, UiMode::EnterCode);
        ui_access::FocusCode();
        return 0;
    }


    case WM_DRAWITEM: {
        const DRAWITEMSTRUCT* dis = (const DRAWITEMSTRUCT*)l;
        if (dis && dis->CtlType == ODT_BUTTON) {
            if (dis->CtlID == IDC_BTN_CONTINUE) {
                UiDrawPrimaryButton(hwnd, (LPDRAWITEMSTRUCT)dis);
                return TRUE;
            }
            TbDrawButton(dis);
            return TRUE; // owner-draw handled
        }
        break;
    }

    case WM_ERASEBKGND:
        // We paint our own background on startup screens.
        if (g_mode != UiMode::Viewer) return 1;
        break;

    case WM_PAINT: {
        if (g_mode == UiMode::Viewer) break;
        PAINTSTRUCT ps{};
        HDC hdc = BeginPaint(hwnd, &ps);
        UiPaintStartupBackground(hwnd, hdc);
        EndPaint(hwnd, &ps);
        return 0;
    }

    case WM_CTLCOLORSTATIC: {
        if (g_mode == UiMode::Viewer) break;
        HDC hdc = (HDC)w;
        HWND hCtl = (HWND)l;
        const UiTheme& t = UiGetTheme();
        int cid = (int)(INT_PTR)GetWindowLongPtrW(hCtl, GWLP_ID);

        // Startup screens repaint often (resize, monitor move/DPI change, progress updates).
        // If we paint static text TRANSPARENT, old glyphs can remain (smearing/duplicates).
        // Paint startup statics OPAQUE with the card background so every repaint clears.
        SetBkMode(hdc, OPAQUE);
        SetBkColor(hdc, t.cardBg);
        if (cid == IDC_LBL_SUB) SetTextColor(hdc, t.subText);
        else SetTextColor(hdc, t.text);
        static HBRUSH hCard = CreateSolidBrush(RGB(255, 255, 255));
        return (INT_PTR)hCard;
    }

    case WM_CTLCOLOREDIT: {
        if (g_mode == UiMode::Viewer) break;
        HDC hdc = (HDC)w;
        SetBkColor(hdc, RGB(255, 255, 255));
        SetTextColor(hdc, UiGetTheme().text);
        static HBRUSH hWhite = CreateSolidBrush(RGB(255, 255, 255));
        return (INT_PTR)hWhite;
    }

    
    case WM_DPICHANGED: {
        // Per-monitor DPI: when the window moves to a display with a different scale,
        // Windows sends WM_DPICHANGED with a suggested new window rect.
        RECT* prc = (RECT*)l;
        if (prc) {
            SetWindowPos(hwnd, nullptr,
                prc->left, prc->top,
                prc->right - prc->left,
                prc->bottom - prc->top,
                SWP_NOZORDER | SWP_NOACTIVATE);
        }

        // Recreate DPI-scaled fonts and re-apply to existing startup controls.
        UiResetStartupFonts(hwnd);
        ApplyStartupFonts(hwnd);

        // Re-layout and repaint using the new DPI.
        SendMessageW(hwnd, WM_SIZE, 0, 0);
        InvalidateRect(hwnd, nullptr, TRUE);
        return 0;
    }

case WM_SIZE: {
    RECT rc{};
    GetClientRect(hwnd, &rc);

    if (g_mode == UiMode::Viewer) {
        int topH = DpiScale(hwnd, 40);
        int statusH = DpiScale(hwnd, 24);

        int thumbsW = g_viewer.IsThumbsVisible() ? DpiScale(hwnd, 220) : 0;

        if (g_hToolbar) SetWindowPos(g_hToolbar, nullptr, 0, 0, rc.right, topH, SWP_NOZORDER);
        if (g_hStatus)  SetWindowPos(g_hStatus,  nullptr, 0, rc.bottom - statusH, rc.right, statusH, SWP_NOZORDER);

        g_viewer.LayoutChildren(rc, topH, statusH, thumbsW);
        return 0;
    }

    // Startup modes: layout isolated screens within the startup card.
    ui_access::Layout(hwnd);
    ui_download::Layout(hwnd);
    return 0;
}

    
case WM_HOTKEY:
    if (g_mode != UiMode::Viewer) return 0;
    if ((int)w == HK_TOGGLE_THUMBS) {
        g_viewer.ToggleThumbs();
        ThumbsButtonSync();
        SendMessageW(hwnd, WM_SIZE, 0, 0);
        StatusUpdate(hwnd);
        return 0;
    }
    break;

	case WM_TIMER:
	    if (w == IDT_INDET_PROGRESS) {
	        if (g_mode == UiMode::Downloading) ui_download::HandleTimer(hwnd);
	        return 0;
	    }
	    break;

    case TTC_WM_THUMB_CLICK:
        g_viewer.GoToPage((int)w);
        StatusUpdate(hwnd);
        return 0;

    case TTC_WM_PAGE_CHANGED:
        g_viewer.SetThumbSelection((int)w);
        StatusUpdate(hwnd);
        return 0;

    case TTC_WM_ZOOM_CHANGED:
        ZoomComboSync();
        StatusUpdate(hwnd);
        return 0;

    case WM_COMMAND: {
    int id = LOWORD(w);
    int code = HIWORD(w);

    // Startup actions
    if (id == IDC_BTN_CONTINUE && code == BN_CLICKED) {
        std::wstring wcode = ui_access::GetCode();
        // trim
        while (!wcode.empty() && iswspace(wcode.front())) wcode.erase(wcode.begin());
        while (!wcode.empty() && iswspace(wcode.back()))  wcode.pop_back();

        if (wcode.empty()) {
            MessageBoxW(hwnd, L"Please enter your access code.", L"Required", MB_OK | MB_ICONWARNING);
            ui_access::FocusCode();
            return 0;
        }

        ui_access::SetNextEnabled(false);

        // Launch worker
        struct WorkerArgs {
            HWND hwnd;
            std::wstring code;
            std::wstring initialPath;
        };
        WorkerArgs* args = new WorkerArgs{ hwnd, wcode, g_initialTtcPath };

        CreateThread(nullptr, 0, [](LPVOID p)->DWORD {
            std::unique_ptr<WorkerArgs> a((WorkerArgs*)p);

            const std::string accessCodeUtf8 = WideToUtf8(a->code);
            const std::string deviceId = GetDeviceIdUtf8();

            std::wstring ttcPath = a->initialPath;
            bool downloaded = false;
            std::wstring downloadedPath;

            // Step 1: download .ttc if no initial file path
            if (ttcPath.empty()) {
                ttc::reader::license::ServerConfig dcfg;
                dcfg.host = L"thetruecerts.com";
                dcfg.path = L"/api/get_download.php";
                dcfg.useHttps = true;
                dcfg.port = 443;
                dcfg.timeoutMs = 15000;

                std::string dlErr;
                std::string dlUrlUtf8;
                if (!ttc::reader::license::GetDownloadUrl(dcfg, accessCodeUtf8, deviceId, dlUrlUtf8, dlErr)) {
                    PostMessageW(a->hwnd, WM_APP_ASYNC_FAIL, 0, (LPARAM)new std::wstring(Utf8ToWide(dlErr)));
                    return 0;
                }

                std::wstring dlUrlW = Utf8ToWide(dlUrlUtf8);
                std::wstring fileName = BasenameFromUrl(dlUrlW);
                std::wstring outPath = MakeSessionDownloadPath(fileName);

                std::string saveErr;
                // Only show the Downloading UI once the device/code check passed
                // (GetDownloadUrl succeeded) and we are about to start transferring bytes.
                PostMessageW(a->hwnd, WM_APP_BEGIN_DOWNLOAD, 0, 0);
                if (!ttc::reader::license::DownloadUrlToFile(dlUrlW, outPath, 20000, saveErr, (void*)a->hwnd, DownloadProgressCb)) {
                    std::wstring msg = L"Cannot write downloaded file Path:" + outPath + L"" + Utf8ToWide(saveErr);
                    PostMessageW(a->hwnd, WM_APP_ASYNC_FAIL, 0, (LPARAM)new std::wstring(msg));
                    return 0;
                }
                downloaded = true;
                downloadedPath = outPath;
                ttcPath = outPath;
            }

            // Step 2: read TTC
            TtcFile file;
            if (!ReadTtcFileW(ttcPath, file)) {
                PostMessageW(a->hwnd, WM_APP_ASYNC_FAIL, 0, (LPARAM)new std::wstring(L"Invalid TTC file"));
                return 0;
            }

            // Step 3: if downloaded, mark delete-on-close (keep handle until exit)
            if (downloaded) {
                // Tell main thread to hold a delete-on-close handle later (we open it here safely)
                HANDLE hDel = CreateFileW(
                    ttcPath.c_str(),
                    GENERIC_READ | DELETE,
                    FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE,
                    NULL,
                    OPEN_EXISTING,
                    FILE_ATTRIBUTE_TEMPORARY | FILE_FLAG_DELETE_ON_CLOSE,
                    NULL
                );
                // Send handle to main thread
                PostMessageW(a->hwnd, WM_APP + 210, (WPARAM)hDel, 0);
            }

            // Step 4: online validation (needs dumpId from file)
            ttc::reader::license::ServerConfig cfg;
            cfg.host = L"thetruecerts.com";
            cfg.path = L"/api/validate.php";
            cfg.useHttps = true;
            cfg.port = 443;
            cfg.timeoutMs = 8000;

            std::string licErr;
            ttc::reader::license::LicenseInfo lic;
            if (!ttc::reader::license::ValidateAccessCodeWithDeviceInfo(cfg, file.metadata.dumpId, accessCodeUtf8, deviceId, lic, licErr)) {
                PostMessageW(a->hwnd, WM_APP_ASYNC_FAIL, 0, (LPARAM)new std::wstring(Utf8ToWide(licErr)));
                return 0;
            }

            // Step 5: version rules
            // (MessageBox from worker is ok, but we keep it simple and enforce here)
            MaybeEnforceUpdate(lic);

            // Step 6: watermark
            std::wstring dumpIdW = Utf8ToWide(lic.dump_id.empty() ? file.metadata.dumpId : lic.dump_id);
            std::wstring dumpNameW = Utf8ToWide(lic.dump_name);
            std::string tag;
            if (!lic.user_email.empty()) tag += lic.user_email;
            if (!lic.user_phone.empty()) {
                if (!tag.empty()) tag += " | ";
                tag += lic.user_phone;
            }
            std::wstring userTagW = Utf8ToWide(tag);

            std::wstring watermark = ttc::reader::BuildAntiShareWatermark(dumpNameW, dumpIdW, userTagW, L"");

            // Step 7: decrypt
            std::string decErr;
            const std::string kContentPassword = "-ddG^|cE;8+Yp8&&"; // must match converter
            auto res = std::make_unique<AsyncResult>();
            if (!ttc::decrypt::DecryptTtcToPdfBytes(file, kContentPassword, res->pdf, &decErr)) {
                std::wstring werr = decErr.empty() ? L"Wrong password or file tampered" : Utf8ToWide(decErr);
                PostMessageW(a->hwnd, WM_APP_ASYNC_FAIL, 0, (LPARAM)new std::wstring(werr));
                return 0;
            }
            res->watermark = std::move(watermark);

            PostMessageW(a->hwnd, WM_APP_ASYNC_OK, 0, (LPARAM)res.release());
            return 0;
        }, args, 0, nullptr);

        return 0;
    }

    if (id == IDC_BTN_CANCEL_DL && code == BN_CLICKED) {
        // For now: close app. (Download is blocking inside WinHTTP; cancel can be added later.)
        PostMessageW(hwnd, WM_CLOSE, 0, 0);
        return 0;
    }if (id == IDC_BTN_PREV && code == BN_CLICKED)    { g_viewer.GoToPage(g_viewer.GetCurrentPage() - 1); StatusUpdate(hwnd); return 0; }
        if (id == IDC_BTN_NEXT && code == BN_CLICKED)    { g_viewer.GoToPage(g_viewer.GetCurrentPage() + 1); StatusUpdate(hwnd); return 0; }
        if (id == IDC_BTN_ZOOMIN && code == BN_CLICKED)  { g_viewer.ZoomIn(); StatusUpdate(hwnd); return 0; }
        if (id == IDC_BTN_ZOOMOUT && code == BN_CLICKED) { g_viewer.ZoomOut(); StatusUpdate(hwnd); return 0; }
        if (id == IDC_BTN_FITW && code == BN_CLICKED)    { g_viewer.FitToWidth(); StatusUpdate(hwnd); return 0; }
        if (id == IDC_BTN_FITP && code == BN_CLICKED)    { g_viewer.FitToPage(); StatusUpdate(hwnd); return 0; }

        if (id == IDC_BTN_THUMBS && code == BN_CLICKED) {
            g_viewer.ToggleThumbs();
            ThumbsButtonSync();
            SendMessageW(hwnd, WM_SIZE, 0, 0);
            StatusUpdate(hwnd);
            return 0;
        }

        if (id == IDC_CMB_ZOOM && code == CBN_SELCHANGE) {
            int sel = (int)SendMessageW(g_hZoomCmb, CB_GETCURSEL, 0, 0);
            switch (sel) {
            case 0: g_viewer.FitToWidth();   break;
            case 1: g_viewer.FitToPage();    break;
            case 2: g_viewer.SetZoom(0.50f); break;
            case 3: g_viewer.SetZoom(0.75f); break;
            case 4: g_viewer.SetZoom(1.00f); break;
            case 5: g_viewer.SetZoom(1.25f); break;
            case 6: g_viewer.SetZoom(1.50f); break;
            case 7: g_viewer.SetZoom(2.00f); break;
            }
            StatusUpdate(hwnd);
            return 0;
        }
        break;
    }

    case WM_DESTROY:
	    KillTimer(hwnd, IDT_INDET_PROGRESS);
        UnregisterHotKey(hwnd, HK_TOGGLE_THUMBS);

        // Close delete-on-close handle (auto-removes downloaded .ttc).
        if (g_ttcDeleteHandle != INVALID_HANDLE_VALUE) {
            CloseHandle(g_ttcDeleteHandle);
            g_ttcDeleteHandle = INVALID_HANDLE_VALUE;
        }
        // Best-effort: remove session folder tree (crash leftovers are handled on next launch).
        if (!g_sessionDir.empty()) {
            DeleteTreeBestEffort(g_sessionDir);
            g_sessionDir.clear();
        }

        if (!g_pdf.empty()) {
            SecureZeroMemory(g_pdf.data(), g_pdf.size());
            g_pdf.clear();
            g_pdf.shrink_to_fit();
        }
        PostQuitMessage(0);
        return 0;
    }

    return DefWindowProcW(hwnd, msg, w, l);
}

int AppRun(HINSTANCE hInst, HINSTANCE /*hPrev*/, LPWSTR /*cmdLine*/, int cmd) {
    EnableDpiAwareness();

    INITCOMMONCONTROLSEX icc{};
    icc.dwSize = sizeof(icc);
    icc.dwICC = ICC_STANDARD_CLASSES | ICC_PROGRESS_CLASS;
    InitCommonControlsEx(&icc);

    // Cleanup sweep for any leftover sessions (crash/power-cut). 24h retention.
    CleanupOldSessions(GetSessionsBaseDir(), 24);

    // Accept .ttc path from file association: ttc-reader.exe "%1"
    int argc = 0;
    LPWSTR* argv = CommandLineToArgvW(GetCommandLineW(), &argc);
    if (argv && argc >= 2) {
        g_initialTtcPath = argv[1];
    }
    if (argv) LocalFree(argv);

    // Register main window class WITH ICONS
    WNDCLASSEXW wc{};
    wc.cbSize = sizeof(wc);
    wc.lpfnWndProc = WndProc;
    wc.hInstance = hInst;
    wc.lpszClassName = L"TTC_UI";
    wc.hCursor = LoadCursor(nullptr, IDC_ARROW);
    wc.hbrBackground = (HBRUSH)(COLOR_WINDOW + 1);

    wc.hIcon = (HICON)LoadImageW(hInst, MAKEINTRESOURCEW(IDI_APPICON),
                                 IMAGE_ICON, 32, 32, LR_DEFAULTCOLOR);
    wc.hIconSm = (HICON)LoadImageW(hInst, MAKEINTRESOURCEW(IDI_APPICON),
                                   IMAGE_ICON, 16, 16, LR_DEFAULTCOLOR);

    RegisterClassExW(&wc);

    HWND hwnd = CreateWindowExW(
        0, wc.lpszClassName, L"TheTrueCerts Reader",
        WS_OVERLAPPEDWINDOW,
        CW_USEDEFAULT, CW_USEDEFAULT, 1200, 800,
        nullptr, nullptr, hInst, nullptr
    );

    if (!hwnd) {
        MessageBoxW(nullptr, L"Failed to create main window", L"Error", MB_OK | MB_ICONERROR);
        return 1;
    }

    // Force icons (title bar/taskbar)
    SetWindowIcons(hwnd, hInst);

    // Prevent screen-capture on supported systems
    SetWindowDisplayAffinity(hwnd, 0);

    ShowWindow(hwnd, cmd);
    UpdateWindow(hwnd);

    MSG msg{};
    while (GetMessageW(&msg, nullptr, 0, 0)) {
        TranslateMessage(&msg);
        DispatchMessageW(&msg);
    }
    return 0;
}