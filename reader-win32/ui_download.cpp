#include "ui_download.h"
#include "ui_common.h"
#include "ui_ids.h"

#include <commctrl.h>
#include <string>

namespace ui_download {

static HWND g_panel=nullptr, g_lbl=nullptr, g_prog=nullptr;
static bool g_determinate=false;
static int g_indetPos=0;
static bool g_timerOn=false;

static void SetLabelPercent(HWND hwnd, int pct) {
    wchar_t buf[128];
    // Avoid a non-ASCII literal ellipsis here: if the compiler treats this source as a legacy codepage
    // (no /utf-8 or BOM), the UTF-8 bytes for "..." can be mis-decoded and show up as "â€¦" at runtime.
    // Use an explicit Unicode escape instead.
    wsprintfW(buf, L"Downloading your files\x2026 %d%%", pct);
    SetWindowTextW(g_lbl, buf);
}

static void StartIndeterminate(HWND hwnd) {
    g_determinate = false;
    g_indetPos = 0;
    SendMessageW(g_prog, PBM_SETRANGE, 0, MAKELPARAM(0, 100));
    SendMessageW(g_prog, PBM_SETPOS, 0, 0);
    SetWindowTextW(g_lbl, L"Downloading your files\x2026");
    if (!g_timerOn) {
        SetTimer(hwnd, IDT_INDET_PROGRESS, 30, nullptr);
        g_timerOn = true;
    }
}

static void StopIndeterminate(HWND hwnd) {
    if (g_timerOn) {
        KillTimer(hwnd, IDT_INDET_PROGRESS);
        g_timerOn = false;
    }
}

void Create(HWND hwnd) {
    UiEnsureStartupFonts(hwnd);
    if (g_panel) return;

    g_panel = CreateWindowExW(0, L"STATIC", L"", WS_CHILD,
        0,0,10,10, hwnd, (HMENU)(INT_PTR)IDC_DL_PANEL, (HINSTANCE)GetModuleHandleW(nullptr), nullptr);
    SetWindowSubclass(g_panel, UiPanelForwardSubclassProc, 1, 0);

    g_lbl = CreateWindowExW(0, L"STATIC", L"Downloading your files\x2026", WS_CHILD | WS_VISIBLE,
        0,0,10,10, g_panel, (HMENU)(INT_PTR)IDC_DL_LABEL, (HINSTANCE)GetModuleHandleW(nullptr), nullptr);
    SendMessageW(g_lbl, WM_SETFONT, (WPARAM)UiTitleFont(), TRUE);

    g_prog = CreateWindowExW(0, PROGRESS_CLASSW, L"", WS_CHILD | WS_VISIBLE,
        0,0,10,10, g_panel, (HMENU)(INT_PTR)IDC_PROGRESS, (HINSTANCE)GetModuleHandleW(nullptr), nullptr);
    SendMessageW(g_prog, PBM_SETRANGE, 0, MAKELPARAM(0, 100));
    SendMessageW(g_prog, PBM_SETSTEP, 1, 0);

    Layout(hwnd);
}

void Layout(HWND hwnd) {
    if (!g_panel) return;
    RECT card = UiGetStartupCardRect(hwnd);
    int pad = UiDpiScale(hwnd, 22);
    int innerW = (card.right - card.left) - pad*2;
    int x = pad;
    int y = pad;

    SetWindowPos(g_panel, nullptr, card.left, card.top, card.right-card.left, card.bottom-card.top, SWP_NOZORDER);
    SetWindowPos(g_lbl, nullptr, x, y, innerW, UiDpiScale(hwnd, 40), SWP_NOZORDER);
    y += UiDpiScale(hwnd, 62);
    SetWindowPos(g_prog, nullptr, x, y, innerW, UiDpiScale(hwnd, 18), SWP_NOZORDER);
}

void Show(bool show) {
    if (!g_panel) return;
    ShowWindow(g_panel, show ? SW_SHOW : SW_HIDE);
    if (show) {
        // start with indeterminate until first progress message
        StartIndeterminate(GetAncestor(g_panel, GA_ROOT));
    } else {
        StopIndeterminate(GetAncestor(g_panel, GA_ROOT));
    }
}

LRESULT HandleProgress(HWND hwnd, WPARAM wParam, LPARAM lParam) {
    if (!g_panel) return 0;
    if (lParam == 0) {
        // determinate
        int pct = (int)wParam;
        if (pct < 0) pct = 0;
        if (pct > 100) pct = 100;
        if (!g_determinate) {
            StopIndeterminate(hwnd);
            g_determinate = true;
        }
        SendMessageW(g_prog, PBM_SETPOS, pct, 0);
        SetLabelPercent(hwnd, pct);
    } else {
        // indeterminate: keep animation, but could update text if desired
        if (g_determinate) {
            g_determinate = false;
            StartIndeterminate(hwnd);
        }
    }
    return 0;
}

void HandleTimer(HWND hwnd) {
    if (!g_panel || g_determinate) return;
    g_indetPos += 2;
    if (g_indetPos > 100) g_indetPos = 0;
    SendMessageW(g_prog, PBM_SETPOS, g_indetPos, 0);
}

}
