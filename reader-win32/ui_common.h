#pragma once
#include <windows.h>

// ---------------------------
// Shared UI helpers for startup flow
// ---------------------------

struct UiTheme {
    COLORREF appBg       = RGB(245, 246, 248);
    COLORREF cardBg      = RGB(255, 255, 255);
    COLORREF cardBorder  = RGB(225, 228, 235);
    COLORREF text        = RGB(25, 25, 25);
    COLORREF subText     = RGB(90, 98, 110);
    COLORREF accent      = RGB(0, 120, 212);
    COLORREF accentHover = RGB(0, 105, 185);
};

const UiTheme& UiGetTheme();

UINT UiGetWindowDpi(HWND hwnd);
int  UiDpiScale(HWND hwnd, int px);

void UiDrawRoundRect(HDC hdc, const RECT& rc, int radiusPx, HBRUSH fillBrush, HPEN borderPen);

// Paints app background + centered card. Call from WM_PAINT for startup modes.
void UiPaintStartupBackground(HWND hwnd, HDC hdc);
RECT UiGetStartupCardRect(HWND hwnd); // latest computed card rect

// Fonts
void UiEnsureStartupFonts(HWND hwnd);
// Recreate fonts using the window's current DPI (used on WM_DPICHANGED).
void UiResetStartupFonts(HWND hwnd);
HFONT UiTitleFont();
HFONT UiBodyFont();

// Owner-drawn "primary" button for Next
void UiDrawPrimaryButton(HWND hwnd, LPDRAWITEMSTRUCT dis);

// Subclass procs
LRESULT CALLBACK UiSimpleBtnSubclassProc(HWND h, UINT m, WPARAM w, LPARAM l, UINT_PTR, DWORD_PTR);
LRESULT CALLBACK UiCodeEditSubclassProc(HWND h, UINT m, WPARAM w, LPARAM l, UINT_PTR, DWORD_PTR);

// Forwards WM_COMMAND / WM_DRAWITEM from a child "panel" window to the main window.
// Owner-draw buttons send WM_DRAWITEM to their immediate parent, so if the controls
// live under a STATIC panel, the main WndProc won't see WM_DRAWITEM unless forwarded.
LRESULT CALLBACK UiPanelForwardSubclassProc(HWND h, UINT m, WPARAM w, LPARAM l, UINT_PTR, DWORD_PTR);
