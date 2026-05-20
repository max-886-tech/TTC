#ifndef NOMINMAX
#define NOMINMAX
#endif
#include <windows.h>
#include <commctrl.h>
#include <cstdint>
#include <cstdio>
#include "ui_flow.h"

// Defined in main.cpp
extern HWND g_hProgress;
extern HWND g_hDlLbl;
extern bool g_progressDeterminate;
extern UINT_PTR g_indetTimer;
extern int g_indetPos;
extern constexpr UINT_PTR IDT_INDET_PROGRESS;

void StartIndeterminateProgress(HWND hwnd) {
    g_progressDeterminate = false;
    g_indetPos = 0;
    if (g_hProgress) {
        SendMessageW(g_hProgress, PBM_SETRANGE, 0, MAKELPARAM(0, 100));
        SendMessageW(g_hProgress, PBM_SETPOS, 0, 0);
    }
    if (!g_indetTimer) {
        g_indetTimer = SetTimer(hwnd, IDT_INDET_PROGRESS, 30, nullptr);
    }
}

void StopIndeterminateProgress(HWND hwnd) {
    if (g_indetTimer) {
        KillTimer(hwnd, IDT_INDET_PROGRESS);
        g_indetTimer = 0;
    }
}

LRESULT HandleAppProgress(HWND hwnd, WPARAM wParam, LPARAM lParam) {
    if ((LPARAM)lParam == (LPARAM)-1) {
        StartIndeterminateProgress(hwnd);

        const uint64_t kb = (uint64_t)wParam;
        const double mb = (double)kb / 1024.0;
        wchar_t buf[128];
        swprintf(buf, 128, L"Downloading your files\x2026  %.1f MB", mb);
        if (g_hDlLbl) SetWindowTextW(g_hDlLbl, buf);
        return 0;
    }

    int pct = (int)wParam;
    if (pct < 0) pct = 0;
    if (pct > 100) pct = 100;

    StopIndeterminateProgress(hwnd);
    g_progressDeterminate = true;

    if (g_hProgress) {
        SendMessageW(g_hProgress, PBM_SETRANGE, 0, MAKELPARAM(0, 100));
        SendMessageW(g_hProgress, PBM_SETPOS, pct, 0);
    }

    wchar_t buf[96];
    swprintf(buf, 96, L"Downloading your files\x2026  %d%%", pct);
    if (g_hDlLbl) SetWindowTextW(g_hDlLbl, buf);
    return 0;
}
