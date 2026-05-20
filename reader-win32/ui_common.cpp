#include "ui_common.h"
#include <commctrl.h>
#include <uxtheme.h>
#include <algorithm>

#pragma comment(lib, "Comctl32.lib")
#pragma comment(lib, "UxTheme.lib")
#pragma comment(lib, "Msimg32.lib")

static UiTheme g_theme;
static RECT g_lastCard{};
static HFONT g_fontTitle = nullptr;
static HFONT g_fontBody  = nullptr;

const UiTheme& UiGetTheme() { return g_theme; }

UINT UiGetWindowDpi(HWND hwnd) {
    HMODULE hUser = GetModuleHandleW(L"user32.dll");
    if (hUser) {
        using GetDpiForWindow_t = UINT(WINAPI*)(HWND);
        auto p = (GetDpiForWindow_t)GetProcAddress(hUser, "GetDpiForWindow");
        if (p) return p(hwnd);
    }
    return 96;
}

int UiDpiScale(HWND hwnd, int px) {
    UINT dpi = UiGetWindowDpi(hwnd);
    return (int)MulDiv(px, (int)dpi, 96);
}

void UiDrawRoundRect(HDC hdc, const RECT& rc, int radiusPx, HBRUSH fillBrush, HPEN borderPen) {
    HGDIOBJ oldBrush = SelectObject(hdc, fillBrush ? (HGDIOBJ)fillBrush : GetStockObject(NULL_BRUSH));
    HGDIOBJ oldPen   = SelectObject(hdc, borderPen ? (HGDIOBJ)borderPen : GetStockObject(NULL_PEN));
    int r = (radiusPx < 0) ? 0 : radiusPx;
    RoundRect(hdc, rc.left, rc.top, rc.right, rc.bottom, r * 2, r * 2);
    SelectObject(hdc, oldPen);
    SelectObject(hdc, oldBrush);
}

static RECT ComputeCard(HWND hwnd) {
    RECT rc{};
    GetClientRect(hwnd, &rc);

    int pad = UiDpiScale(hwnd, 18);
    int cardW = (int)(rc.right * 0.46);
    int minW = UiDpiScale(hwnd, 420);
    int maxW = UiDpiScale(hwnd, 640);
    if (cardW < minW) cardW = minW;
    if (cardW > maxW) cardW = maxW;

    int cardH = UiDpiScale(hwnd, 240);
    int cx = (rc.right - cardW) / 2;
    int cy = (rc.bottom - cardH) / 2;
    if (cy < pad) cy = pad;

    return RECT{ cx, cy, cx + cardW, cy + cardH };
}

// NOTE: Do NOT cache the card rect across resizes/DPI changes.
// WM_SIZE triggers layout before WM_PAINT, and if we return a stale cached rect
// the panel will be positioned/sized incorrectly ("breaks" while resizing).
RECT UiGetStartupCardRect(HWND hwnd) {
    g_lastCard = ComputeCard(hwnd);
    return g_lastCard;
}

void UiResetStartupFonts(HWND hwnd) {
    if (g_fontTitle) { DeleteObject(g_fontTitle); g_fontTitle = nullptr; }
    if (g_fontBody)  { DeleteObject(g_fontBody);  g_fontBody  = nullptr; }
    UiEnsureStartupFonts(hwnd);
}

void UiPaintStartupBackground(HWND hwnd, HDC hdc) {
    RECT rc{};
    GetClientRect(hwnd, &rc);

    // App background
    HBRUSH brBg = CreateSolidBrush(g_theme.appBg);
    FillRect(hdc, &rc, brBg);
    DeleteObject(brBg);

    RECT card = ComputeCard(hwnd);
    g_lastCard = card;

    // Shadow
    RECT sh = card;
    OffsetRect(&sh, UiDpiScale(hwnd, 2), UiDpiScale(hwnd, 3));

    HDC mem = CreateCompatibleDC(hdc);
    HBITMAP bmp = CreateCompatibleBitmap(hdc, sh.right - sh.left, sh.bottom - sh.top);
    HGDIOBJ old = SelectObject(mem, bmp);
    RECT mrc{ 0,0, sh.right - sh.left, sh.bottom - sh.top };
    HBRUSH brSh = CreateSolidBrush(RGB(0, 0, 0));
    FillRect(mem, &mrc, brSh);
    DeleteObject(brSh);
    BLENDFUNCTION bf{ AC_SRC_OVER, 0, 22, 0 };
    AlphaBlend(hdc, sh.left, sh.top, mrc.right, mrc.bottom, mem, 0, 0, mrc.right, mrc.bottom, bf);
    SelectObject(mem, old);
    DeleteObject(bmp);
    DeleteDC(mem);

    // Card
    HBRUSH brCard = CreateSolidBrush(g_theme.cardBg);
    HPEN penBorder = CreatePen(PS_SOLID, 1, g_theme.cardBorder);
    UiDrawRoundRect(hdc, card, UiDpiScale(hwnd, 14), brCard, penBorder);
    DeleteObject(brCard);
    DeleteObject(penBorder);
}

void UiEnsureStartupFonts(HWND hwnd) {
    if (g_fontTitle && g_fontBody) return;
    int dpi = (int)UiGetWindowDpi(hwnd);
    g_fontTitle = CreateFontW(-MulDiv(18, dpi, 72), 0, 0, 0, FW_BOLD, FALSE, FALSE, FALSE,
        DEFAULT_CHARSET, OUT_DEFAULT_PRECIS, CLIP_DEFAULT_PRECIS, CLEARTYPE_QUALITY,
        DEFAULT_PITCH | FF_SWISS, L"Segoe UI");
    g_fontBody = CreateFontW(-MulDiv(11, dpi, 72), 0, 0, 0, FW_NORMAL, FALSE, FALSE, FALSE,
        DEFAULT_CHARSET, OUT_DEFAULT_PRECIS, CLIP_DEFAULT_PRECIS, CLEARTYPE_QUALITY,
        DEFAULT_PITCH | FF_SWISS, L"Segoe UI");
}

HFONT UiTitleFont() { return g_fontTitle; }
HFONT UiBodyFont() { return g_fontBody; }

// Button state kept in GWLP_USERDATA
struct BtnState { bool hot=false; bool down=false; };

static BtnState* GetBtnState(HWND h) {
    auto* st = (BtnState*)GetWindowLongPtrW(h, GWLP_USERDATA);
    if (!st) {
        st = new BtnState();
        SetWindowLongPtrW(h, GWLP_USERDATA, (LONG_PTR)st);
    }
    return st;
}

void UiDrawPrimaryButton(HWND hwnd, LPDRAWITEMSTRUCT dis) {
    if (!dis) return;
    const UiTheme& t = UiGetTheme();
    HDC hdc = dis->hDC;
    RECT rc = dis->rcItem;

    bool disabled = (dis->itemState & ODS_DISABLED) != 0;
    BtnState* st = (BtnState*)GetWindowLongPtrW(dis->hwndItem, GWLP_USERDATA);
    bool hot = st ? st->hot : false;
    bool down = st ? st->down : false;

    COLORREF fill = disabled ? RGB(210,215,222) : (hot ? t.accentHover : t.accent);
    if (down && !disabled) fill = t.accentHover;

    HBRUSH brFill = CreateSolidBrush(fill);
    HPEN penBr = CreatePen(PS_SOLID, 1, fill);
    UiDrawRoundRect(hdc, rc, UiDpiScale(hwnd, 10), brFill, penBr);
    DeleteObject(brFill);
    DeleteObject(penBr);

    // Text
    wchar_t text[128]{};
    GetWindowTextW(dis->hwndItem, text, 127);
    SetBkMode(hdc, TRANSPARENT);
    SetTextColor(hdc, RGB(255,255,255));
    HFONT old = (HFONT)SelectObject(hdc, UiBodyFont());
    DrawTextW(hdc, text, -1, &rc, DT_CENTER | DT_VCENTER | DT_SINGLELINE);
    SelectObject(hdc, old);
}

LRESULT CALLBACK UiSimpleBtnSubclassProc(HWND h, UINT m, WPARAM w, LPARAM l, UINT_PTR, DWORD_PTR) {
    BtnState* st = GetBtnState(h);
    switch (m) {
        case WM_NCDESTROY: {
            auto* p = (BtnState*)GetWindowLongPtrW(h, GWLP_USERDATA);
            if (p) delete p;
            SetWindowLongPtrW(h, GWLP_USERDATA, 0);
            break;
        }
        case WM_MOUSEMOVE: {
            if (!st->hot) {
                st->hot = true;
                TRACKMOUSEEVENT tme{ sizeof(tme), TME_LEAVE, h, 0 };
                TrackMouseEvent(&tme);
                InvalidateRect(h, nullptr, TRUE);
            }
            break;
        }
        case WM_MOUSELEAVE:
            st->hot = false;
            st->down = false;
            InvalidateRect(h, nullptr, TRUE);
            break;
        case WM_LBUTTONDOWN:
            st->down = true;
            SetCapture(h);
            InvalidateRect(h, nullptr, TRUE);
            break;
        case WM_LBUTTONUP:
            if (GetCapture() == h) ReleaseCapture();
            // Ensure click is delivered even when the button lives under a panel
            // (some configurations can swallow WM_COMMAND for BS_OWNERDRAW).
            if (st->down && IsWindowEnabled(h)) {
                POINT pt{};
                GetCursorPos(&pt);
                ScreenToClient(h, &pt);
                RECT rc{};
                GetClientRect(h, &rc);
                if (PtInRect(&rc, pt)) {
                    HWND root = GetAncestor(h, GA_ROOT);
                    SendMessageW(root, WM_COMMAND, MAKEWPARAM(GetDlgCtrlID(h), BN_CLICKED), 0);
                }
            }
            st->down = false;
            InvalidateRect(h, nullptr, TRUE);
            break;
    }
    return DefSubclassProc(h, m, w, l);
}

LRESULT CALLBACK UiCodeEditSubclassProc(HWND h, UINT m, WPARAM w, LPARAM l, UINT_PTR, DWORD_PTR) {
    if (m == WM_KEYDOWN && w == VK_RETURN) {
        HWND root = GetAncestor(h, GA_ROOT);
        // Trigger the Next action (button may not be a direct child of root)
        SendMessageW(root, WM_COMMAND, MAKEWPARAM(2103 /*IDC_BTN_CONTINUE*/, BN_CLICKED), 0);
        return 0;
    }
    return DefSubclassProc(h, m, w, l);
}

LRESULT CALLBACK UiPanelForwardSubclassProc(HWND h, UINT m, WPARAM w, LPARAM l, UINT_PTR, DWORD_PTR) {
    switch (m) {
        case WM_COMMAND:
        case WM_DRAWITEM:
        case WM_CTLCOLORSTATIC:
        case WM_CTLCOLOREDIT:
        case WM_CTLCOLORBTN: {
            HWND parent = GetParent(h);
            if (parent) return SendMessageW(parent, m, w, l);
            break;
        }
    }
    return DefSubclassProc(h, m, w, l);
}
