#pragma once

// Fix Windows min/max macro conflict BEFORE windows.h
#ifndef NOMINMAX
#define NOMINMAX
#endif

#include <windows.h>
#include <windowsx.h>   // GET_X_LPARAM / GET_Y_LPARAM
#include <vector>
#include <string>
#include <unordered_map>

#include "fpdfview.h"

// parent notifications
static constexpr UINT TTC_WM_THUMB_CLICK  = WM_APP + 101; // wParam = pageIndex
static constexpr UINT TTC_WM_PAGE_CHANGED = WM_APP + 102; // wParam = pageIndex
static constexpr UINT TTC_WM_ZOOM_CHANGED = WM_APP + 103; // wParam = zoomPercent

class PdfViewer {
public:
    enum class ZoomMode { Custom, FitWidth, FitPage };

    PdfViewer();
    ~PdfViewer();

    bool CreateChildWindows(HWND hParent);
    bool LoadFromMemory(const std::vector<uint8_t>& pdfBytes);

    HWND GetThumbsHwnd() const { return m_hwndThumbs; }
    HWND GetViewHwnd() const { return m_hwndView; }

    int  GetPageCount() const { return m_pageCount; }
    int  GetCurrentPage() const { return m_currentPage; }

    void GoToPage(int pageIndex);

    void SetZoomMode(ZoomMode mode);
    ZoomMode GetZoomMode() const { return m_zoomMode; }

    void SetZoom(float z);     // 0.25..4.0
    float GetZoom() const { return m_zoom; }
    int   GetZoomPercent() const { return (int)(m_zoom * 100.0f + 0.5f); }

    void ZoomIn();
    void ZoomOut();
    void FitToWidth();
    void FitToPage();

    void SetWatermarkText(const std::wstring& t) { m_watermarkText = t; }

    // parent WM_SIZE calls this
    void LayoutChildren(RECT rcClient, int topH, int statusH, int thumbsW);

    void SetThumbSelection(int pageIndex);

    // ----------------------------
    // Thumbnails pane show/hide
    // ----------------------------
    void SetThumbsVisible(bool visible);
    void ToggleThumbs();
    bool IsThumbsVisible() const { return m_thumbsVisible; }

private:
    static LRESULT CALLBACK ViewWndProc(HWND, UINT, WPARAM, LPARAM);
    static LRESULT CALLBACK ThumbsWndProc(HWND, UINT, WPARAM, LPARAM);

    void RecalcLayout();
    void UpdateViewScrollBar();
    void UpdateThumbScrollBar();

    void RenderView(HDC hdc, const RECT& rcClient);
    void RenderThumbs(HDC hdc, const RECT& rcClient);

    void EnsurePageBitmap(int pageIndex);
    void EnsureThumbBitmap(int pageIndex);

    int  PageFromY(int docY) const;
    void NotifyPageChangedIfNeeded();

    // Persistent backbuffers (important for smooth scroll)
    struct BackBuffer {
        HDC     dc = nullptr;
        HBITMAP bmp = nullptr;
        HBITMAP old = nullptr;
        int     w = 0;
        int     h = 0;

        void Destroy() {
            if (dc) {
                SelectObject(dc, old);
                DeleteObject(bmp);
                DeleteDC(dc);
                dc = nullptr; bmp = nullptr; old = nullptr;
                w = h = 0;
            }
        }

        void Ensure(HDC refDC, int W, int H) {
            if (dc && W == w && H == h) return;
            Destroy();
            w = W; h = H;
            dc = CreateCompatibleDC(refDC);
            bmp = CreateCompatibleBitmap(refDC, W, H);
            old = (HBITMAP)SelectObject(dc, bmp);
        }
    };

private:
    HWND m_hwndParent = nullptr;
    HWND m_hwndThumbs = nullptr;
    HWND m_hwndView = nullptr;

    FPDF_DOCUMENT m_doc = nullptr;
    const std::vector<uint8_t>* m_pdfRef = nullptr;
    int m_pageCount = 0;

    struct PageLayout {
        int y = 0;          // doc coords
        int w = 0;          // px at zoom
        int h = 0;
        double wPt = 0.0;   // points
        double hPt = 0.0;
    };

    std::vector<PageLayout> m_layout;

    // Total document size (px)
    int m_docTotalW = 0;
    int m_docTotalH = 0;

    // View scroll offsets (px)
    int m_scrollX = 0;
    int m_scrollY = 0;

    ZoomMode m_zoomMode = ZoomMode::FitWidth;
    float m_zoom = 1.0f;

    bool m_dragging = false;
    POINT m_lastPt{};

    int m_currentPage = 0;

    int m_topH = 0;
    int m_statusH = 0;
    int m_thumbsW = 220;

    // Thumbs visibility state
    bool m_thumbsVisible = true;
    int  m_thumbsWStored = 220; // last remembered width

    struct BmpCache {
        int w = 0, h = 0, stride = 0;
        int zoomKey = 0;
        std::vector<uint8_t> pixels; // BGRA
    };
    std::unordered_map<unsigned int, BmpCache> m_pageCache;

    struct ThumbCache {
        int w = 0, h = 0, stride = 0;
        std::vector<uint8_t> pixels; // BGRA
    };
    std::unordered_map<int, ThumbCache> m_thumbCache;

    int m_thumbScrollY = 0;
    int m_thumbItemH = 160;
    int m_thumbW = 130;
    int m_thumbSelected = 0;

    std::wstring m_watermarkText;

    BackBuffer m_viewBB;
    BackBuffer m_thumbsBB;
};
