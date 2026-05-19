// pdf_viewer.cpp

#include "pdf_viewer.h"
#include <cmath>
#include <cstring>
#include "watermark.h"

// Avoid std::min/max (even with NOMINMAX) – keep it bulletproof
static inline int  IMin(int a, int b) { return (a < b) ? a : b; }
static inline int  IMax(int a, int b) { return (a > b) ? a : b; }
static inline int  IClamp(int v, int lo, int hi) { return (v < lo) ? lo : (v > hi) ? hi : v; }
static inline float FClamp(float v, float lo, float hi) { return (v < lo) ? lo : (v > hi) ? hi : v; }
static inline int  IAbs(int v) { return v < 0 ? -v : v; }

static const wchar_t* kViewClass  = L"TTC_PDF_VIEW";
static const wchar_t* kThumbClass = L"TTC_PDF_THUMBS";

PdfViewer::PdfViewer() {
    FPDF_InitLibrary();
}

PdfViewer::~PdfViewer() {
    m_viewBB.Destroy();
    m_thumbsBB.Destroy();

    m_pageCache.clear();
    m_thumbCache.clear();

    if (m_doc) FPDF_CloseDocument(m_doc);
    FPDF_DestroyLibrary();
}

bool PdfViewer::CreateChildWindows(HWND hParent) {
    m_hwndParent = hParent;

    // View class
    {
        WNDCLASSW wc{};
        wc.lpfnWndProc = PdfViewer::ViewWndProc;
        wc.hInstance = (HINSTANCE)GetModuleHandleW(nullptr);
        wc.lpszClassName = kViewClass;
        wc.hCursor = LoadCursor(nullptr, IDC_ARROW);
        wc.hbrBackground = (HBRUSH)(COLOR_BTNFACE + 1);
        RegisterClassW(&wc);
    }

    // Thumbs class
    {
        WNDCLASSW wc{};
        wc.lpfnWndProc = PdfViewer::ThumbsWndProc;
        wc.hInstance = (HINSTANCE)GetModuleHandleW(nullptr);
        wc.lpszClassName = kThumbClass;
        wc.hCursor = LoadCursor(nullptr, IDC_ARROW);
        wc.hbrBackground = (HBRUSH)(COLOR_BTNFACE + 1);
        RegisterClassW(&wc);
    }

    m_hwndThumbs = CreateWindowExW(
        WS_EX_CLIENTEDGE,
        kThumbClass, L"",
        WS_CHILD | WS_VISIBLE | WS_VSCROLL | WS_CLIPSIBLINGS,
        0, 0, 200, 200,
        hParent, (HMENU)(INT_PTR)2001, (HINSTANCE)GetModuleHandleW(nullptr), this);

    // IMPORTANT: add WS_HSCROLL to support horizontal panning after zoom
    m_hwndView = CreateWindowExW(
        WS_EX_CLIENTEDGE,
        kViewClass, L"",
        WS_CHILD | WS_VISIBLE | WS_VSCROLL | WS_HSCROLL | WS_CLIPSIBLINGS | WS_CLIPCHILDREN,
        200, 0, 200, 200,
        hParent, (HMENU)(INT_PTR)2002, (HINSTANCE)GetModuleHandleW(nullptr), this);

    return m_hwndThumbs && m_hwndView;
}

bool PdfViewer::LoadFromMemory(const std::vector<uint8_t>& pdfBytes) {
    if (m_doc) {
        m_pageCache.clear();
        m_thumbCache.clear();
        FPDF_CloseDocument(m_doc);
        m_doc = nullptr;
    }

    m_pdfRef = &pdfBytes;
    m_doc = FPDF_LoadMemDocument(pdfBytes.data(), (int)pdfBytes.size(), nullptr);
    if (!m_doc) return false;

    m_pageCount = FPDF_GetPageCount(m_doc);
    m_currentPage = 0;
    m_thumbSelected = 0;

    m_scrollX = 0;
    m_scrollY = 0;

    m_thumbScrollY = 0;

    RecalcLayout();
    UpdateViewScrollBar();
    UpdateThumbScrollBar();

    if (m_hwndView) InvalidateRect(m_hwndView, nullptr, TRUE);
    if (m_hwndThumbs && m_thumbsVisible) InvalidateRect(m_hwndThumbs, nullptr, TRUE);
    return true;
}

// ----------------------------
// Thumbnails show/hide
// ----------------------------
void PdfViewer::SetThumbsVisible(bool visible) {
    if (m_thumbsVisible == visible) return;
    m_thumbsVisible = visible;

    // Remember last width when hiding; restore when showing.
    if (!m_thumbsVisible) {
        m_thumbsWStored = m_thumbsW;
        if (m_hwndThumbs) ShowWindow(m_hwndThumbs, SW_HIDE);
    } else {
        if (m_thumbsWStored < 140) m_thumbsWStored = 220;
        if (m_hwndThumbs) ShowWindow(m_hwndThumbs, SW_SHOWNA);
    }

    if (m_hwndParent) {
        RECT rc{};
        GetClientRect(m_hwndParent, &rc);
        LayoutChildren(rc, m_topH, m_statusH, m_thumbsWStored);
    }
}

void PdfViewer::ToggleThumbs() {
    SetThumbsVisible(!m_thumbsVisible);
}

void PdfViewer::SetZoomMode(ZoomMode mode) {
    m_zoomMode = mode;
    m_pageCache.clear();
    RecalcLayout();
    UpdateViewScrollBar();
    if (m_hwndView) InvalidateRect(m_hwndView, nullptr, TRUE);

    if (m_hwndParent) {
        SendMessageW(m_hwndParent, TTC_WM_ZOOM_CHANGED, (WPARAM)GetZoomPercent(), 0);
    }
}

void PdfViewer::SetZoom(float z) {
    m_zoomMode = ZoomMode::Custom;
    m_zoom = FClamp(z, 0.25f, 4.0f);
    m_pageCache.clear();
    RecalcLayout();
    UpdateViewScrollBar();
    if (m_hwndView) InvalidateRect(m_hwndView, nullptr, TRUE);

    if (m_hwndParent) {
        SendMessageW(m_hwndParent, TTC_WM_ZOOM_CHANGED, (WPARAM)GetZoomPercent(), 0);
    }
}

void PdfViewer::ZoomIn()  { SetZoom(m_zoom + 0.10f); }
void PdfViewer::ZoomOut() { SetZoom(m_zoom - 0.10f); }
void PdfViewer::FitToWidth() { SetZoomMode(ZoomMode::FitWidth); }
void PdfViewer::FitToPage()  { SetZoomMode(ZoomMode::FitPage); }

void PdfViewer::GoToPage(int pageIndex) {
    if (!m_doc || m_layout.empty() || !m_hwndView) return;
    pageIndex = IClamp(pageIndex, 0, IMax(0, m_pageCount - 1));

    int oldY = m_scrollY;
    int targetY = m_layout[pageIndex].y - 10;
    m_scrollY = IMax(0, targetY);

    UpdateViewScrollBar();

    int dy = oldY - m_scrollY;
    if (dy != 0) {
        ScrollWindowEx(m_hwndView, 0, dy, nullptr, nullptr, nullptr, nullptr, SW_INVALIDATE);
    } else {
        InvalidateRect(m_hwndView, nullptr, FALSE);
    }

    m_currentPage = pageIndex;
    SetThumbSelection(pageIndex);

    if (m_hwndParent) {
        SendMessageW(m_hwndParent, TTC_WM_PAGE_CHANGED, (WPARAM)m_currentPage, 0);
    }
}

void PdfViewer::SetThumbSelection(int pageIndex) {
    m_thumbSelected = IClamp(pageIndex, 0, IMax(0, m_pageCount - 1));
    if (m_hwndThumbs && m_thumbsVisible) InvalidateRect(m_hwndThumbs, nullptr, FALSE);
}

int PdfViewer::PageFromY(int docY) const {
    if (m_layout.empty()) return 0;
    for (int i = 0; i < (int)m_layout.size(); ++i) {
        const auto& p = m_layout[i];
        if (docY >= p.y && docY < p.y + p.h + 20) return i;
    }
    return (int)m_layout.size() - 1;
}

void PdfViewer::NotifyPageChangedIfNeeded() {
    if (!m_hwndView) return;
    RECT rc{};
    GetClientRect(m_hwndView, &rc);
    int viewH = rc.bottom - rc.top;
    int centerY = m_scrollY + viewH / 2;

    int newPage = IClamp(PageFromY(centerY), 0, IMax(0, m_pageCount - 1));
    if (newPage != m_currentPage) {
        m_currentPage = newPage;
        SetThumbSelection(newPage);
        if (m_hwndParent) {
            SendMessageW(m_hwndParent, TTC_WM_PAGE_CHANGED, (WPARAM)m_currentPage, 0);
        }
    }
}

void PdfViewer::RecalcLayout() {
    if (!m_doc || !m_hwndView) return;

    RECT rc{};
    GetClientRect(m_hwndView, &rc);
    int viewW = rc.right - rc.left;
    int viewH = rc.bottom - rc.top;
    if (viewW <= 0 || viewH <= 0) return;

    m_layout.assign(m_pageCount, {});

    double maxWpt = 1.0;
    for (int i = 0; i < m_pageCount; ++i) {
        double wPt = 0, hPt = 0;
        FPDF_GetPageSizeByIndex(m_doc, i, &wPt, &hPt);
        m_layout[i].wPt = wPt;
        m_layout[i].hPt = hPt;
        if (wPt > maxWpt) maxWpt = wPt;
    }

    const int marginX = 24;
    const int marginY = 20;
    const int gapY = 18;

    if (m_zoomMode == ZoomMode::FitWidth) {
        double zw = (double)(viewW - 2 * marginX) / maxWpt;
        m_zoom = FClamp((float)zw, 0.25f, 4.0f);
        m_pageCache.clear();
    } else if (m_zoomMode == ZoomMode::FitPage) {
        int idx = IClamp(m_currentPage, 0, IMax(0, m_pageCount - 1));
        double wPt = m_layout[idx].wPt, hPt = m_layout[idx].hPt;

        double zw = (double)(viewW - 2 * marginX) / wPt;
        double zh = (double)(viewH - 2 * marginY) / hPt;
        double z = (zw < zh) ? zw : zh;
        m_zoom = FClamp((float)z, 0.25f, 4.0f);
        m_pageCache.clear();
    }

    int y = marginY;

    int maxWpx = 1;
    for (int i = 0; i < m_pageCount; ++i) {
        int w = (int)std::lround(m_layout[i].wPt * m_zoom);
        int h = (int)std::lround(m_layout[i].hPt * m_zoom);
        m_layout[i].w = IMax(1, w);
        m_layout[i].h = IMax(1, h);
        m_layout[i].y = y;
        y += h + gapY;

        if (m_layout[i].w > maxWpx) maxWpx = m_layout[i].w;
    }

    // Total doc size in pixels (used by scrollbars)
    m_docTotalW = maxWpx + 2 * marginX;
    m_docTotalH = y + marginY;
}

void PdfViewer::UpdateViewScrollBar() {
    if (!m_hwndView) return;

    RECT rc{};
    GetClientRect(m_hwndView, &rc);
    int viewW = rc.right - rc.left;
    int viewH = rc.bottom - rc.top;

    int maxScrollY = IMax(0, m_docTotalH - viewH);
    int maxScrollX = IMax(0, m_docTotalW - viewW);

    m_scrollY = IClamp(m_scrollY, 0, maxScrollY);
    m_scrollX = IClamp(m_scrollX, 0, maxScrollX);

    // Vertical
    SCROLLINFO sv{};
    sv.cbSize = sizeof(sv);
    sv.fMask = SIF_ALL;
    sv.nMin = 0;
    sv.nMax = m_docTotalH;
    sv.nPage = (UINT)viewH;
    sv.nPos = m_scrollY;
    SetScrollInfo(m_hwndView, SB_VERT, &sv, TRUE);
    ShowScrollBar(m_hwndView, SB_VERT, maxScrollY > 0);

    // Horizontal
    SCROLLINFO sh{};
    sh.cbSize = sizeof(sh);
    sh.fMask = SIF_ALL;
    sh.nMin = 0;
    sh.nMax = m_docTotalW;
    sh.nPage = (UINT)viewW;
    sh.nPos = m_scrollX;
    SetScrollInfo(m_hwndView, SB_HORZ, &sh, TRUE);
    ShowScrollBar(m_hwndView, SB_HORZ, maxScrollX > 0);
}

void PdfViewer::UpdateThumbScrollBar() {
    if (!m_hwndThumbs) return;
    if (!m_thumbsVisible) return;

    RECT rc{};
    GetClientRect(m_hwndThumbs, &rc);
    int viewH = rc.bottom - rc.top;

    m_thumbW = IMax(90, (rc.right - rc.left) - 24);
    m_thumbItemH = m_thumbW + 40;

    int totalH = m_pageCount * m_thumbItemH + 20;
    int maxScroll = IMax(0, totalH - viewH);
    m_thumbScrollY = IClamp(m_thumbScrollY, 0, maxScroll);

    SCROLLINFO si{};
    si.cbSize = sizeof(si);
    si.fMask = SIF_ALL;
    si.nMin = 0;
    si.nMax = totalH;
    si.nPage = (UINT)viewH;
    si.nPos = m_thumbScrollY;
    SetScrollInfo(m_hwndThumbs, SB_VERT, &si, TRUE);
}

void PdfViewer::EnsureThumbBitmap(int pageIndex) {
    if (!m_doc) return;
    if (m_thumbCache.find(pageIndex) != m_thumbCache.end()) return;

    double wPt = 0, hPt = 0;
    FPDF_GetPageSizeByIndex(m_doc, pageIndex, &wPt, &hPt);
    if (wPt <= 0.0 || hPt <= 0.0) return;

    float z = (float)m_thumbW / (float)wPt;
    int w = IMax(1, (int)std::lround(wPt * z));
    int h = IMax(1, (int)std::lround(hPt * z));
    int stride = w * 4;

    ThumbCache t{};
    t.w = w; t.h = h; t.stride = stride;
    t.pixels.resize((size_t)stride * (size_t)h);

    FPDF_PAGE page = FPDF_LoadPage(m_doc, pageIndex);
    if (!page) return;

    FPDF_BITMAP bmp = FPDFBitmap_CreateEx(w, h, FPDFBitmap_BGRA, t.pixels.data(), stride);
    FPDFBitmap_FillRect(bmp, 0, 0, w, h, 0xFFFFFFFF);
    FPDF_RenderPageBitmap(bmp, page, 0, 0, w, h, 0, FPDF_ANNOT);

    FPDFBitmap_Destroy(bmp);
    FPDF_ClosePage(page);

    m_thumbCache[pageIndex] = (ThumbCache&&)t;
}

void PdfViewer::EnsurePageBitmap(int pageIndex) {
    if (!m_doc) return;
    int zoomKey = IClamp((int)std::lround(m_zoom * 1000.0f), 250, 4000);
    unsigned int key = ((unsigned int)pageIndex << 16) ^ (unsigned int)(zoomKey & 0xFFFF);
    if (m_pageCache.find(key) != m_pageCache.end()) return;

    const auto& pl = m_layout[pageIndex];
    int w = pl.w;
    int h = pl.h;
    int stride = w * 4;

    BmpCache c{};
    c.w = w; c.h = h; c.stride = stride; c.zoomKey = zoomKey;
    c.pixels.resize((size_t)stride * (size_t)h);

    FPDF_PAGE page = FPDF_LoadPage(m_doc, pageIndex);
    if (!page) return;

    FPDF_BITMAP bmp = FPDFBitmap_CreateEx(w, h, FPDFBitmap_BGRA, c.pixels.data(), stride);
    FPDFBitmap_FillRect(bmp, 0, 0, w, h, 0xFFFFFFFF);
    FPDF_RenderPageBitmap(bmp, page, 0, 0, w, h, 0, FPDF_ANNOT);

    FPDFBitmap_Destroy(bmp);
    FPDF_ClosePage(page);

    m_pageCache[key] = (BmpCache&&)c;

    if ((int)m_pageCache.size() > 10) {
        std::vector<unsigned int> del;
        del.reserve(m_pageCache.size());
        for (const auto& kv : m_pageCache) {
            int p = (int)(kv.first >> 16);
            if (IAbs(p - m_currentPage) > 3) del.push_back(kv.first);
        }
        for (auto k : del) m_pageCache.erase(k);
    }
}

void PdfViewer::RenderView(HDC hdc, const RECT& rcClient) {
    if (!m_doc) return;

    HBRUSH bg = CreateSolidBrush(RGB(230, 230, 230));
    FillRect(hdc, &rcClient, bg);
    DeleteObject(bg);

    int viewW = rcClient.right - rcClient.left;
    int viewH = rcClient.bottom - rcClient.top;
    int top = m_scrollY;
    int bottom = m_scrollY + viewH;

    const int marginX = 24;

    for (int i = 0; i < m_pageCount; ++i) {
        const auto& p = m_layout[i];
        int py0 = p.y;
        int py1 = p.y + p.h;

        if (py1 < top) continue;
        if (py0 > bottom) break;

        EnsurePageBitmap(i);

        int zoomKey = IClamp((int)std::lround(m_zoom * 1000.0f), 250, 4000);
        unsigned int key = ((unsigned int)i << 16) ^ (unsigned int)(zoomKey & 0xFFFF);
        auto it = m_pageCache.find(key);
        if (it == m_pageCache.end()) continue;

        const auto& bmp2 = it->second;

        // Horizontal positioning:
        // - Center if page fits.
        // - Otherwise use m_scrollX to pan.
        int x;
        if (p.w + 2 * marginX <= viewW) {
            x = (viewW - p.w) / 2;
        } else {
            x = marginX - m_scrollX;
        }

        int y = p.y - m_scrollY;

        RECT shadow{ x + 3, y + 3, x + p.w + 3, y + p.h + 3 };
        HBRUSH sh = CreateSolidBrush(RGB(210, 210, 210));
        FillRect(hdc, &shadow, sh);
        DeleteObject(sh);

        RECT border{ x - 1, y - 1, x + p.w + 1, y + p.h + 1 };
        HBRUSH br = CreateSolidBrush(RGB(180, 180, 180));
        FrameRect(hdc, &border, br);
        DeleteObject(br);

        BITMAPINFO bmi{};
        bmi.bmiHeader.biSize = sizeof(BITMAPINFOHEADER);
        bmi.bmiHeader.biWidth = bmp2.w;
        bmi.bmiHeader.biHeight = -bmp2.h;
        bmi.bmiHeader.biPlanes = 1;
        bmi.bmiHeader.biBitCount = 32;
        bmi.bmiHeader.biCompression = BI_RGB;

        StretchDIBits(hdc, x, y, bmp2.w, bmp2.h,
            0, 0, bmp2.w, bmp2.h,
            bmp2.pixels.data(), &bmi, DIB_RGB_COLORS, SRCCOPY);
    }

    if (!m_watermarkText.empty()) {
        ttc::reader::DrawWatermark(hdc, rcClient, m_watermarkText, m_scrollY);
    }

    NotifyPageChangedIfNeeded();
}

void PdfViewer::RenderThumbs(HDC hdc, const RECT& rcClient) {
    if (!m_doc) return;

    HBRUSH bg = CreateSolidBrush(RGB(245, 245, 245));
    FillRect(hdc, &rcClient, bg);
    DeleteObject(bg);

    int w = rcClient.right - rcClient.left;
    int y0 = 12;

    SetBkMode(hdc, TRANSPARENT);
    SelectObject(hdc, GetStockObject(DEFAULT_GUI_FONT));

    for (int i = 0; i < m_pageCount; ++i) {
        int itemTop = y0 + i * m_thumbItemH - m_thumbScrollY;
        int itemBot = itemTop + m_thumbItemH;
        if (itemBot < 0) continue;
        if (itemTop > (rcClient.bottom - rcClient.top)) break;

        EnsureThumbBitmap(i);
        auto it = m_thumbCache.find(i);
        if (it == m_thumbCache.end()) continue;
        const auto& t = it->second;

        if (i == m_thumbSelected) {
            RECT sel{ 2, itemTop + 4, w - 2, itemTop + m_thumbItemH - 4 };
            HBRUSH sb = CreateSolidBrush(RGB(220, 235, 255));
            FillRect(hdc, &sel, sb);
            DeleteObject(sb);
            HBRUSH fb = CreateSolidBrush(RGB(120, 170, 240));
            FrameRect(hdc, &sel, fb);
            DeleteObject(fb);
        }

        int pad = 10;
        int thumbY = itemTop + 10;
        int x = pad + (w - 2 * pad - t.w) / 2;
        x = IMax(pad, x);

        RECT brc{ x - 1, thumbY - 1, x + t.w + 1, thumbY + t.h + 1 };
        HBRUSH br = CreateSolidBrush(RGB(190, 190, 190));
        FrameRect(hdc, &brc, br);
        DeleteObject(br);

        BITMAPINFO bmi{};
        bmi.bmiHeader.biSize = sizeof(BITMAPINFOHEADER);
        bmi.bmiHeader.biWidth = t.w;
        bmi.bmiHeader.biHeight = -t.h;
        bmi.bmiHeader.biPlanes = 1;
        bmi.bmiHeader.biBitCount = 32;
        bmi.bmiHeader.biCompression = BI_RGB;

        StretchDIBits(hdc, x, thumbY, t.w, t.h,
            0, 0, t.w, t.h,
            t.pixels.data(), &bmi, DIB_RGB_COLORS, SRCCOPY);

        wchar_t label[32];
        wsprintfW(label, L"%d", i + 1);
        TextOutW(hdc, pad, thumbY + t.h + 6, label, lstrlenW(label));
    }
}

void PdfViewer::LayoutChildren(RECT rcClient, int topH, int statusH, int thumbsW) {
    m_topH = topH;
    m_statusH = statusH;
    m_thumbsW = thumbsW;

    int W = rcClient.right - rcClient.left;
    int H = rcClient.bottom - rcClient.top;

    int clientTop = topH;
    int clientBottom = H - statusH;
    int clientH = IMax(0, clientBottom - clientTop);

    int thumbsWidth = 0;
    if (m_thumbsVisible) {
        thumbsWidth = IClamp(thumbsW, 140, 340);
        thumbsWidth = IMin(thumbsWidth, W / 2);
    }

    if (m_hwndThumbs) {
        if (m_thumbsVisible) {
            ShowWindow(m_hwndThumbs, SW_SHOWNA);
            SetWindowPos(m_hwndThumbs, nullptr, 0, clientTop, thumbsWidth, clientH,
                SWP_NOZORDER | SWP_NOACTIVATE);
        } else {
            ShowWindow(m_hwndThumbs, SW_HIDE);
            SetWindowPos(m_hwndThumbs, nullptr, 0, clientTop, 0, clientH,
                SWP_NOZORDER | SWP_NOACTIVATE);
        }
    }

    if (m_hwndView) {
        SetWindowPos(m_hwndView, nullptr, thumbsWidth, clientTop, W - thumbsWidth, clientH,
            SWP_NOZORDER | SWP_NOACTIVATE);
    }

    RecalcLayout();
    UpdateViewScrollBar();

    if (m_thumbsVisible) {
        UpdateThumbScrollBar();
        if (m_hwndThumbs) InvalidateRect(m_hwndThumbs, nullptr, TRUE);
    }

    if (m_hwndView) InvalidateRect(m_hwndView, nullptr, TRUE);
}

// -------------------- Window procs --------------------

LRESULT CALLBACK PdfViewer::ViewWndProc(HWND hwnd, UINT msg, WPARAM w, LPARAM l) {
    PdfViewer* self = (PdfViewer*)GetWindowLongPtrW(hwnd, GWLP_USERDATA);

    if (msg == WM_NCCREATE) {
        auto* cs = (CREATESTRUCTW*)l;
        SetWindowLongPtrW(hwnd, GWLP_USERDATA, (LONG_PTR)cs->lpCreateParams);
        return TRUE;
    }
    if (!self) return DefWindowProcW(hwnd, msg, w, l);

    switch (msg) {
    case WM_SIZE:
        self->RecalcLayout();
        self->UpdateViewScrollBar();
        return 0;

    case WM_ERASEBKGND:
        return 1; // critical: avoid flicker

    case WM_MOUSEWHEEL: {
        int delta = GET_WHEEL_DELTA_WPARAM(w);

        if (GetKeyState(VK_CONTROL) & 0x8000) {
            self->SetZoom(self->GetZoom() + (delta > 0 ? 0.10f : -0.10f));
            return 0;
        }

        // SHIFT + wheel -> horizontal scroll
        if (GetKeyState(VK_SHIFT) & 0x8000) {
            int oldX = self->m_scrollX;
            self->m_scrollX -= delta; // wheel up = left, wheel down = right
            self->UpdateViewScrollBar();

            int dx = oldX - self->m_scrollX;
            if (dx != 0) {
                ScrollWindowEx(hwnd, dx, 0, nullptr, nullptr, nullptr, nullptr, SW_INVALIDATE);
            }
            return 0;
        }

        int oldY = self->m_scrollY;
        self->m_scrollY -= delta; // keep your current feel
        self->UpdateViewScrollBar();

        int dy = oldY - self->m_scrollY;
        if (dy != 0) {
            ScrollWindowEx(hwnd, 0, dy, nullptr, nullptr, nullptr, nullptr, SW_INVALIDATE);
        }
        return 0;
    }

    case WM_MOUSEHWHEEL: {
        // Trackpads / horizontal wheels
        int delta = GET_WHEEL_DELTA_WPARAM(w);
        int oldX = self->m_scrollX;

        self->m_scrollX += delta;
        self->UpdateViewScrollBar();

        int dx = oldX - self->m_scrollX;
        if (dx != 0) {
            ScrollWindowEx(hwnd, dx, 0, nullptr, nullptr, nullptr, nullptr, SW_INVALIDATE);
        }
        return 0;
    }

    case WM_VSCROLL: {
        SCROLLINFO si{};
        si.cbSize = sizeof(si);
        si.fMask = SIF_ALL;
        GetScrollInfo(hwnd, SB_VERT, &si);

        int oldY = self->m_scrollY;
        int y = si.nPos;

        switch (LOWORD(w)) {
        case SB_LINEUP:     y -= 40; break;
        case SB_LINEDOWN:   y += 40; break;
        case SB_PAGEUP:     y -= (int)si.nPage; break;
        case SB_PAGEDOWN:   y += (int)si.nPage; break;
        case SB_THUMBTRACK: y = si.nTrackPos; break;
        }

        self->m_scrollY = y;
        self->UpdateViewScrollBar();

        int dy = oldY - self->m_scrollY;
        if (dy != 0) {
            ScrollWindowEx(hwnd, 0, dy, nullptr, nullptr, nullptr, nullptr, SW_INVALIDATE);
        }
        return 0;
    }

    case WM_HSCROLL: {
        SCROLLINFO si{};
        si.cbSize = sizeof(si);
        si.fMask = SIF_ALL;
        GetScrollInfo(hwnd, SB_HORZ, &si);

        int oldX = self->m_scrollX;
        int x = si.nPos;

        switch (LOWORD(w)) {
        case SB_LINELEFT:   x -= 40; break;
        case SB_LINERIGHT:  x += 40; break;
        case SB_PAGELEFT:   x -= (int)si.nPage; break;
        case SB_PAGERIGHT:  x += (int)si.nPage; break;
        case SB_THUMBTRACK: x = si.nTrackPos; break;
        }

        self->m_scrollX = x;
        self->UpdateViewScrollBar();

        int dx = oldX - self->m_scrollX;
        if (dx != 0) {
            ScrollWindowEx(hwnd, dx, 0, nullptr, nullptr, nullptr, nullptr, SW_INVALIDATE);
        }
        return 0;
    }

    case WM_LBUTTONDOWN:
        self->m_dragging = true;
        self->m_lastPt.x = GET_X_LPARAM(l);
        self->m_lastPt.y = GET_Y_LPARAM(l);
        SetCapture(hwnd);
        SetFocus(hwnd);
        return 0;

    case WM_LBUTTONUP:
        self->m_dragging = false;
        ReleaseCapture();
        return 0;

    case WM_MOUSEMOVE:
        if (self->m_dragging) {
            POINT pt{ GET_X_LPARAM(l), GET_Y_LPARAM(l) };
            int dxMouse = pt.x - self->m_lastPt.x;
            int dyMouse = pt.y - self->m_lastPt.y;
            self->m_lastPt = pt;

            int oldX = self->m_scrollX;
            int oldY = self->m_scrollY;

            // Drag moves the content with the mouse (natural panning)
            self->m_scrollX -= dxMouse;
            self->m_scrollY -= dyMouse;

            self->UpdateViewScrollBar();

            int dx = oldX - self->m_scrollX;
            int dy = oldY - self->m_scrollY;
            if (dx != 0 || dy != 0) {
                ScrollWindowEx(hwnd, dx, dy, nullptr, nullptr, nullptr, nullptr, SW_INVALIDATE);
            }
        }
        return 0;

    case WM_PAINT: {
        PAINTSTRUCT ps;
        HDC hdc = BeginPaint(hwnd, &ps);

        RECT rcClient{};
        GetClientRect(hwnd, &rcClient);
        int W = rcClient.right - rcClient.left;
        int H = rcClient.bottom - rcClient.top;

        // Persistent backbuffer
        self->m_viewBB.Ensure(hdc, W, H);
        HDC mem = self->m_viewBB.dc;

        // Clip to paint region so we only render the exposed strip
        HRGN rgn = CreateRectRgnIndirect(&ps.rcPaint);
        SelectClipRgn(mem, rgn);

        self->RenderView(mem, rcClient);

        SelectClipRgn(mem, nullptr);
        DeleteObject(rgn);

        int pw = ps.rcPaint.right - ps.rcPaint.left;
        int ph = ps.rcPaint.bottom - ps.rcPaint.top;

        BitBlt(hdc,
            ps.rcPaint.left, ps.rcPaint.top, pw, ph,
            mem,
            ps.rcPaint.left, ps.rcPaint.top,
            SRCCOPY);

        EndPaint(hwnd, &ps);
        return 0;
    }
    }
    return DefWindowProcW(hwnd, msg, w, l);
}

LRESULT CALLBACK PdfViewer::ThumbsWndProc(HWND hwnd, UINT msg, WPARAM w, LPARAM l) {
    PdfViewer* self = (PdfViewer*)GetWindowLongPtrW(hwnd, GWLP_USERDATA);

    if (msg == WM_NCCREATE) {
        auto* cs = (CREATESTRUCTW*)l;
        SetWindowLongPtrW(hwnd, GWLP_USERDATA, (LONG_PTR)cs->lpCreateParams);
        return TRUE;
    }
    if (!self) return DefWindowProcW(hwnd, msg, w, l);

    switch (msg) {
    case WM_SIZE:
        self->UpdateThumbScrollBar();
        InvalidateRect(hwnd, nullptr, TRUE);
        return 0;

    case WM_ERASEBKGND:
        return 1;

    case WM_MOUSEWHEEL: {
        int delta = GET_WHEEL_DELTA_WPARAM(w);

        int old = self->m_thumbScrollY;
        self->m_thumbScrollY -= delta;
        self->UpdateThumbScrollBar();

        int dy = old - self->m_thumbScrollY;
        if (dy != 0) {
            ScrollWindowEx(hwnd, 0, dy, nullptr, nullptr, nullptr, nullptr, SW_INVALIDATE);
        }
        return 0;
    }

    case WM_VSCROLL: {
        SCROLLINFO si{};
        si.cbSize = sizeof(si);
        si.fMask = SIF_ALL;
        GetScrollInfo(hwnd, SB_VERT, &si);

        int old = self->m_thumbScrollY;
        int y = si.nPos;

        switch (LOWORD(w)) {
        case SB_LINEUP:     y -= 40; break;
        case SB_LINEDOWN:   y += 40; break;
        case SB_PAGEUP:     y -= (int)si.nPage; break;
        case SB_PAGEDOWN:   y += (int)si.nPage; break;
        case SB_THUMBTRACK: y = si.nTrackPos; break;
        }

        self->m_thumbScrollY = y;
        self->UpdateThumbScrollBar();

        int dy = old - self->m_thumbScrollY;
        if (dy != 0) {
            ScrollWindowEx(hwnd, 0, dy, nullptr, nullptr, nullptr, nullptr, SW_INVALIDATE);
        }
        return 0;
    }

    case WM_LBUTTONDOWN: {
        int y = GET_Y_LPARAM(l) + self->m_thumbScrollY - 12;
        int idx = y / self->m_thumbItemH;
        idx = IClamp(idx, 0, IMax(0, self->m_pageCount - 1));

        self->SetThumbSelection(idx);
        SendMessageW(self->m_hwndParent, TTC_WM_THUMB_CLICK, (WPARAM)idx, 0);
        SetFocus(self->m_hwndView);
        return 0;
    }

    case WM_PAINT: {
        PAINTSTRUCT ps;
        HDC hdc = BeginPaint(hwnd, &ps);

        RECT rcClient{};
        GetClientRect(hwnd, &rcClient);
        int W = rcClient.right - rcClient.left;
        int H = rcClient.bottom - rcClient.top;

        self->m_thumbsBB.Ensure(hdc, W, H);
        HDC mem = self->m_thumbsBB.dc;

        HRGN rgn = CreateRectRgnIndirect(&ps.rcPaint);
        SelectClipRgn(mem, rgn);

        self->RenderThumbs(mem, rcClient);

        SelectClipRgn(mem, nullptr);
        DeleteObject(rgn);

        int pw = ps.rcPaint.right - ps.rcPaint.left;
        int ph = ps.rcPaint.bottom - ps.rcPaint.top;

        BitBlt(hdc,
            ps.rcPaint.left, ps.rcPaint.top, pw, ph,
            mem,
            ps.rcPaint.left, ps.rcPaint.top,
            SRCCOPY);

        EndPaint(hwnd, &ps);
        return 0;
    }
    }
    return DefWindowProcW(hwnd, msg, w, l);
}
