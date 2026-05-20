#include "ui_access.h"
#include "ui_common.h"
#include "ui_ids.h"

#include <commctrl.h>

namespace ui_access {

static HWND g_panel=nullptr, g_title=nullptr, g_sub=nullptr, g_edit=nullptr, g_next=nullptr;

void Create(HWND hwnd) {
    UiEnsureStartupFonts(hwnd);
    if (g_panel) return;

    g_panel = CreateWindowExW(0, L"STATIC", L"", WS_CHILD | WS_VISIBLE,
        0,0,10,10, hwnd, (HMENU)(INT_PTR)IDC_START_PANEL, (HINSTANCE)GetModuleHandleW(nullptr), nullptr);
    SetWindowSubclass(g_panel, UiPanelForwardSubclassProc, 1, 0);

    g_title = CreateWindowExW(0, L"STATIC", L"Enter Access Code", WS_CHILD | WS_VISIBLE,
        0,0,10,10, g_panel, (HMENU)(INT_PTR)IDC_LBL_TITLE, (HINSTANCE)GetModuleHandleW(nullptr), nullptr);
    SendMessageW(g_title, WM_SETFONT, (WPARAM)UiTitleFont(), TRUE);

    g_sub = CreateWindowExW(0, L"STATIC", L"Please enter your access code to continue.", WS_CHILD | WS_VISIBLE,
        0,0,10,10, g_panel, (HMENU)(INT_PTR)IDC_LBL_SUB, (HINSTANCE)GetModuleHandleW(nullptr), nullptr);
    SendMessageW(g_sub, WM_SETFONT, (WPARAM)UiBodyFont(), TRUE);

    g_edit = CreateWindowExW(WS_EX_CLIENTEDGE, L"EDIT", L"", WS_CHILD | WS_VISIBLE | ES_AUTOHSCROLL,
        0,0,10,10, g_panel, (HMENU)(INT_PTR)IDC_CODE_EDIT, (HINSTANCE)GetModuleHandleW(nullptr), nullptr);
    SendMessageW(g_edit, WM_SETFONT, (WPARAM)UiBodyFont(), TRUE);
    SetWindowSubclass(g_edit, UiCodeEditSubclassProc, 1, 0);

    // Use explicit Unicode escape for the arrow to avoid codepage/UTF-8 source issues.
    g_next = CreateWindowExW(0, L"BUTTON", L"Next \u2192", WS_CHILD | WS_VISIBLE | BS_OWNERDRAW,
        0,0,10,10, g_panel, (HMENU)(INT_PTR)IDC_BTN_CONTINUE, (HINSTANCE)GetModuleHandleW(nullptr), nullptr);
    SendMessageW(g_next, WM_SETFONT, (WPARAM)UiBodyFont(), TRUE);
    SetWindowSubclass(g_next, UiSimpleBtnSubclassProc, 1, 0);

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

    SetWindowPos(g_title, nullptr, x, y, innerW, UiDpiScale(hwnd, 34), SWP_NOZORDER);
    y += UiDpiScale(hwnd, 44);
    SetWindowPos(g_sub, nullptr, x, y, innerW, UiDpiScale(hwnd, 22), SWP_NOZORDER);
    y += UiDpiScale(hwnd, 40);

    SetWindowPos(g_edit, nullptr, x, y, innerW, UiDpiScale(hwnd, 30), SWP_NOZORDER);
    y += UiDpiScale(hwnd, 52);

    SetWindowPos(g_next, nullptr, x, y, innerW, UiDpiScale(hwnd, 42), SWP_NOZORDER);
}

void Show(bool show) {
    if (!g_panel) return;
    ShowWindow(g_panel, show ? SW_SHOW : SW_HIDE);
    if (show) FocusCode();
}

std::wstring GetCode() {
    if (!g_edit) return L"";
    wchar_t buf[512]{};
    GetWindowTextW(g_edit, buf, 511);
    return std::wstring(buf);
}

void ClearCode() {
    if (g_edit) SetWindowTextW(g_edit, L"");
}

void FocusCode() {
    if (g_edit) SetFocus(g_edit);
}

void SetNextEnabled(bool enabled) {
    if (g_next) EnableWindow(g_next, enabled ? TRUE : FALSE);
}

}
