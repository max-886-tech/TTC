#ifndef NOMINMAX
#define NOMINMAX
#endif

#include <windows.h>
#include <commctrl.h>
#pragma comment(lib, "Comctl32.lib")
#include <commdlg.h>
#include <shellscalingapi.h>
#include <shellapi.h>   // CommandLineToArgvW
#include <uxtheme.h>
#pragma comment(lib, "UxTheme.lib")
#include <shlobj.h>     // SHCreateDirectoryExW, SHGetKnownFolderPath
#include <string>
#include <vector>
#include <chrono>
#include <cstdint>

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
// ---------------------------
// App version (semver)
// ---------------------------
static const char* APP_VERSION = "1.0.0";

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
    SetProcessDpiAwareness(PROCESS_PER_MONITOR_DPI_AWARE);
}

static int DpiScale(HWND hwnd, int px) {
    UINT dpi = GetDpiForWindow(hwnd);
    return MulDiv(px, (int)dpi, 96);
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

static void TbEnsureFonts(HWND hwnd) {
    if (g_fontUi && g_fontIcon) return;
    int dpi = (int)GetDpiForWindow(hwnd);
    int uiPx   = -MulDiv(10, dpi, 72); // 10pt
    int iconPx = -MulDiv(12, dpi, 72); // 12pt, slightly larger

    g_fontUi = CreateFontW(uiPx, 0, 0, 0, FW_SEMIBOLD, FALSE, FALSE, FALSE,
        DEFAULT_CHARSET, OUT_DEFAULT_PRECIS, CLIP_DEFAULT_PRECIS, CLEARTYPE_QUALITY,
        DEFAULT_PITCH | FF_SWISS, L"Segoe UI");

    g_fontIcon = CreateFontW(iconPx, 0, 0, 0, FW_SEMIBOLD, FALSE, FALSE, FALSE,
        DEFAULT_CHARSET, OUT_DEFAULT_PRECIS, CLIP_DEFAULT_PRECIS, CLEARTYPE_QUALITY,
        DEFAULT_PITCH | FF_SWISS, L"Segoe UI Symbol");
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

static void DrawRoundRect(HDC hdc, const RECT& rc, int r, HBRUSH br, HPEN pen) {
    HGDIOBJ oldBr = SelectObject(hdc, br);
    HGDIOBJ oldPen = SelectObject(hdc, pen);
    RoundRect(hdc, rc.left, rc.top, rc.right, rc.bottom, r, r);
    SelectObject(hdc, oldBr);
    SelectObject(hdc, oldPen);
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

    HBRUSH brFill = CreateSolidBrush(fill);
    HPEN penBr = CreatePen(PS_SOLID, 1, border);

    // slight inset when pressed
    if (pressed) { rc.left++; rc.top++; }

    SetBkMode(hdc, TRANSPARENT);
    DrawRoundRect(hdc, rc, 10, brFill, penBr);

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
    ofn.lpstrFilter = L"Dumps Files (*.ttc)\0*.ttc\0";
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
    std::wstring base = lad.empty() ? L".\\TheTrueCerts.com\\sessions\\" : (lad + L"\\TheTrueCerts.com\\sessions\\");
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
    if (base.size() < 4 || _wcsicmp(base.c_str() + (base.size() - 4), L".ttc") != 0) {
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
// Access code dialog
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
        auto* cs = (CREATESTRUCTW*)l;
        st = (PwdDlgState*)cs->lpCreateParams;
        SetWindowLongPtrW(hwnd, GWLP_USERDATA, (LONG_PTR)st);

        int pad = DpiScaleSys(12);
        int wlbl = DpiScaleSys(90);
        int editW = DpiScaleSys(260);
        int rowH = DpiScaleSys(26);
        int btnW = DpiScaleSys(90);
        int btnH = DpiScaleSys(28);

        CreateWindowExW(0, L"STATIC", L"Access Code:",
            WS_CHILD | WS_VISIBLE,
            pad, pad + 2, wlbl, rowH, hwnd, nullptr, GetModuleHandleW(nullptr), nullptr);

        st->hEdit = CreateWindowExW(WS_EX_CLIENTEDGE, L"EDIT", L"",
            WS_CHILD | WS_VISIBLE | ES_PASSWORD | ES_AUTOHSCROLL,
            pad + wlbl, pad, editW, rowH, hwnd, (HMENU)(INT_PTR)1001, GetModuleHandleW(nullptr), nullptr);

        CreateWindowExW(0, L"BUTTON", L"OK",
            WS_CHILD | WS_VISIBLE | BS_DEFPUSHBUTTON,
            pad + wlbl + editW - btnW * 2 - DpiScaleSys(10), pad + rowH + DpiScaleSys(14), btnW, btnH,
            hwnd, (HMENU)(INT_PTR)IDOK, GetModuleHandleW(nullptr), nullptr);

        CreateWindowExW(0, L"BUTTON", L"Cancel",
            WS_CHILD | WS_VISIBLE,
            pad + wlbl + editW - btnW, pad + rowH + DpiScaleSys(14), btnW, btnH,
            hwnd, (HMENU)(INT_PTR)IDCANCEL, GetModuleHandleW(nullptr), nullptr);

        SetFocus(st->hEdit);
        return 0;
    }

    case WM_DRAWITEM: {
    const DRAWITEMSTRUCT* dis = (const DRAWITEMSTRUCT*)l;
    if (dis && dis->CtlType == ODT_BUTTON) {
        TbDrawButton(dis);
        return TRUE;
    }
    break;
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

static bool PromptAccessCode(std::wstring& outPwd) {
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
        L"Enter Access Code",
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
    case WM_CREATE: {
        int topH = DpiScale(hwnd, 40);
        int btnH = DpiScale(hwnd, 28);
        int pad  = DpiScale(hwnd, 6);

        g_hToolbar = CreateWindowExW(0, L"STATIC", L"", WS_CHILD | WS_VISIBLE,
            0, 0, 10, topH, hwnd, (HMENU)(INT_PTR)0, (HINSTANCE)GetModuleHandleW(nullptr), nullptr);

        TbEnsureFonts(hwnd);

auto mkBtn = [&](int id, const wchar_t* text, const wchar_t* tip, int x, int wbtnPx = 64, bool icon=false) -> HWND {
    HWND h = CreateWindowExW(0, L"BUTTON", text,
        WS_CHILD | WS_VISIBLE | WS_TABSTOP | BS_OWNERDRAW,
        x, pad, DpiScale(hwnd, wbtnPx), btnH, hwnd, (HMENU)(INT_PTR)id,
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

// Icon-style buttons (single glyph) + tooltips
mkBtn(IDC_BTN_PREV,    L"\u2039", L"Previous page", x, 42, true); x += DpiScale(hwnd, 48);
mkBtn(IDC_BTN_NEXT,    L"\u203A", L"Next page",     x, 42, true); x += DpiScale(hwnd, 48);
mkBtn(IDC_BTN_ZOOMOUT, L"\u2212", L"Zoom out",      x, 42, true); x += DpiScale(hwnd, 48);
mkBtn(IDC_BTN_ZOOMIN,  L"\u002B", L"Zoom in",       x, 42, true); x += DpiScale(hwnd, 48);

mkBtn(IDC_BTN_FITW,    L"Fit width", L"Fit to width", x, 86, false); x += DpiScale(hwnd, 92);
mkBtn(IDC_BTN_FITP,    L"Fit page",  L"Fit to page",  x, 86, false); x += DpiScale(hwnd, 92);

g_hBtnThumbs = mkBtn(IDC_BTN_THUMBS, L"Thumbnails", L"Show / hide thumbnails (Ctrl+T)", x, 108, false);
x += DpiScale(hwnd, 116);

        g_hZoomCmb = CreateWindowExW(0, L"COMBOBOX", L"",
            WS_CHILD | WS_VISIBLE | CBS_DROPDOWNLIST | WS_VSCROLL,
            x + pad, pad, DpiScale(hwnd, 140), btnH + DpiScale(hwnd, 120),
            hwnd, (HMENU)(INT_PTR)IDC_CMB_ZOOM, (HINSTANCE)GetModuleHandleW(nullptr), nullptr);
        SendMessageW(g_hZoomCmb, WM_SETFONT, (WPARAM)g_fontUi, TRUE);
        SetWindowTheme(g_hZoomCmb, L"Explorer", nullptr);


        ZoomComboFill();

        int statusH = DpiScale(hwnd, 24);
        g_hStatus = CreateWindowExW(0, L"STATIC", L"", WS_CHILD | WS_VISIBLE | SS_LEFT,
            0, 0, 10, statusH, hwnd, (HMENU)(INT_PTR)IDC_STATUS, (HINSTANCE)GetModuleHandleW(nullptr), nullptr);
        SendMessageW(g_hStatus, WM_SETFONT, (WPARAM)g_fontUi, TRUE);


        g_viewer.CreateChildWindows(hwnd);

        RegisterHotKey(hwnd, HK_TOGGLE_THUMBS, MOD_CONTROL, 'T');

        ThumbsButtonSync();
        return 0;
    }


    case WM_DRAWITEM: {
        const DRAWITEMSTRUCT* dis = (const DRAWITEMSTRUCT*)l;
        if (dis && dis->CtlType == ODT_BUTTON) {
            TbDrawButton(dis);
            return TRUE; // owner-draw handled
        }
        break;
    }

    case WM_SIZE: {
        RECT rc{};
        GetClientRect(hwnd, &rc);

        int topH = DpiScale(hwnd, 40);
        int statusH = DpiScale(hwnd, 24);

        int thumbsW = g_viewer.IsThumbsVisible() ? DpiScale(hwnd, 220) : 0;

        if (g_hToolbar) SetWindowPos(g_hToolbar, nullptr, 0, 0, rc.right, topH, SWP_NOZORDER);
        if (g_hStatus)  SetWindowPos(g_hStatus,  nullptr, 0, rc.bottom - statusH, rc.right, statusH, SWP_NOZORDER);

        g_viewer.LayoutChildren(rc, topH, statusH, thumbsW);
        return 0;
    }

    case WM_HOTKEY:
        if ((int)w == HK_TOGGLE_THUMBS) {
            g_viewer.ToggleThumbs();
            ThumbsButtonSync();
            SendMessageW(hwnd, WM_SIZE, 0, 0);
            StatusUpdate(hwnd);
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

        if (id == IDC_BTN_PREV && code == BN_CLICKED)    { g_viewer.GoToPage(g_viewer.GetCurrentPage() - 1); StatusUpdate(hwnd); return 0; }
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

int WINAPI wWinMain(HINSTANCE hInst, HINSTANCE, PWSTR, int cmd) {
    EnableDpiAwareness();

    // Cleanup sweep for any leftover sessions (crash/power-cut). 24h retention.
    CleanupOldSessions(GetSessionsBaseDir(), 24);

    // Accept .ttc path from file association: ttc-reader.exe "%1"
    std::wstring wpath;
        bool downloaded = false;
    std::wstring downloadedPath;
int argc = 0;
    LPWSTR* argv = CommandLineToArgvW(GetCommandLineW(), &argc);
    if (argv && argc >= 2) {
        wpath = argv[1];
    }
    if (argv) LocalFree(argv);

    // If no file argument, we will ask for Access Code and download the .ttc automatically.
    // If a file argument is provided (file association), we keep the existing behavior.
    std::string accessCodeUtf8;
    if (wpath.empty()) {
        std::wstring wcode;
        if (!PromptAccessCode(wcode)) {
            return 0; // user cancelled
        }
        accessCodeUtf8 = WideToUtf8(wcode);

        // Call server: get_download.php -> { ok:true, url:"..." }
        ttc::reader::license::ServerConfig dcfg;
        dcfg.host = L"thetruecerts.com";
        dcfg.path = L"/api/get_download.php";
        dcfg.useHttps = true;
        dcfg.port = 443;
        dcfg.timeoutMs = 15000;

        std::string deviceId = GetDeviceIdUtf8();
        std::string dlErr;
        std::string dlUrlUtf8;
        if (!ttc::reader::license::GetDownloadUrl(dcfg, accessCodeUtf8, deviceId, dlUrlUtf8, dlErr)) {
            MessageBoxW(nullptr, Utf8ToWide(dlErr).c_str(), L"Download Error", MB_OK | MB_ICONERROR);
            return 1;
        }

        std::wstring dlUrlW = Utf8ToWide(dlUrlUtf8);
        std::wstring fileName = BasenameFromUrl(dlUrlW);
        std::wstring outPath = MakeSessionDownloadPath(fileName);

        std::string saveErr;
        if (!ttc::reader::license::DownloadUrlToFile(dlUrlW, outPath, 20000, saveErr)) {
            // include full path for easier debugging
            std::wstring msg = L"Cannot write downloaded file\n\nPath:\n" + outPath + L"\n\n" + Utf8ToWide(saveErr);
            MessageBoxW(nullptr, msg.c_str(), L"Download Error", MB_OK | MB_ICONERROR);
            return 1;
        }
        downloaded = true;
        downloadedPath = outPath;

        wpath = outPath;
}

    // If still empty (shouldn't), allow manual open.
    if (wpath.empty()) {
        wpath = OpenTtcDialog(nullptr);
    }
    if (wpath.empty()) return 0;

    TtcFile file;
    if (!ReadTtcFileW(wpath, file)) {
        MessageBoxW(nullptr, L"Invalid TTC file", L"Error", MB_OK | MB_ICONERROR);
        return 1;
    }

    // If the file was auto-downloaded, now mark it delete-on-close.
    // We do this AFTER parsing, otherwise some file open modes can fail on Windows.
    if (downloaded && g_ttcDeleteHandle == INVALID_HANDLE_VALUE) {
        g_ttcDeleteHandle = CreateFileW(
            downloadedPath.c_str(),
            GENERIC_READ | DELETE,
            FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE,
            NULL,
            OPEN_EXISTING,
            FILE_ATTRIBUTE_TEMPORARY | FILE_FLAG_DELETE_ON_CLOSE,
            NULL
        );
        // If this fails, we still cleanup the session folder on WM_DESTROY (best-effort).
    }


    // Ask password / access code (if not already entered for download)
    std::string pwd;
    if (!accessCodeUtf8.empty()) {
        pwd = accessCodeUtf8;
    } else {
        std::wstring wpwd;
        if (!PromptAccessCode(wpwd)) {
            return 0; // user cancelled
        }
        pwd = WideToUtf8(wpwd);
    }

    // Online validate password/code BEFORE decrypt
    ttc::reader::license::ServerConfig cfg;
    cfg.host = L"thetruecerts.com";
    cfg.path = L"/api/validate.php";
    cfg.useHttps = true;
    cfg.port = 443;
    cfg.timeoutMs = 8000;

    std::string licErr;
    std::string deviceId = GetDeviceIdUtf8();

    ttc::reader::license::LicenseInfo lic;
    if (!ttc::reader::license::ValidateAccessCodeWithDeviceInfo(cfg, file.metadata.examId, pwd, deviceId, lic, licErr)) {
        MessageBoxW(nullptr, Utf8ToWide(licErr).c_str(), L"Access Code / License Error", MB_OK | MB_ICONERROR);
        return 1;
    }

    // Version check (update rules returned by validate.php)
    MaybeEnforceUpdate(lic);

    // Build watermark from server fields
    std::wstring examIdW = Utf8ToWide(lic.exam_id.empty() ? file.metadata.examId : lic.exam_id);
    std::wstring examNameW = Utf8ToWide(lic.exam_name);

    std::string tag;
    if (!lic.user_email.empty()) tag += lic.user_email;
    if (!lic.user_phone.empty()) {
        if (!tag.empty()) tag += " | ";
        tag += lic.user_phone;
    }
    std::wstring userTagW = Utf8ToWide(tag);

    g_watermarkText = ttc::reader::BuildAntiShareWatermark(
        examNameW,
        examIdW,
        userTagW,
        L""
    );

    // Decrypt
    std::string decErr;
    const std::string kContentPassword = "-ddG^|cE;8+Yp8&&"; // must match converter
    if (!ttc::decrypt::DecryptTtcToPdfBytes(file, kContentPassword, g_pdf, &decErr)) {
        std::wstring werr(decErr.begin(), decErr.end());
        MessageBoxW(nullptr, werr.empty() ? L"Wrong password or file tampered" : werr.c_str(),
            L"Error", MB_OK | MB_ICONERROR);
        return 1;
    }

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
        0, wc.lpszClassName, L"TrueCerts Reader",
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

    SetWindowDisplayAffinity(hwnd, WDA_EXCLUDEFROMCAPTURE);

    ShowWindow(hwnd, cmd);
    UpdateWindow(hwnd);

    if (!g_viewer.LoadFromMemory(g_pdf)) {
        MessageBoxW(nullptr, L"Failed to load PDF (PDFium)", L"Error", MB_OK | MB_ICONERROR);
        return 1;
    }

    g_viewer.SetWatermarkText(g_watermarkText);
    g_viewer.SetZoomMode(PdfViewer::ZoomMode::FitWidth);

    ThumbsButtonSync();
    SendMessageW(hwnd, WM_SIZE, 0, 0);

    StatusUpdate(hwnd);

    MSG msg{};
    while (GetMessageW(&msg, nullptr, 0, 0)) {
        TranslateMessage(&msg);
        DispatchMessageW(&msg);
    }
    return 0;
}