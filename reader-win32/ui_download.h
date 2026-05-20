#pragma once
#include <windows.h>

// Downloading screen + progress handling
namespace ui_download {

void Create(HWND hwnd);
void Layout(HWND hwnd);
void Show(bool show);

// Progress messages come from worker thread via WM_APP_PROGRESS.
// determinate: wParam=pct (0-100), lParam=0
// indeterminate: wParam=downloaded_kb, lParam=-1
LRESULT HandleProgress(HWND hwnd, WPARAM wParam, LPARAM lParam);

// For timer-based indeterminate animation
void HandleTimer(HWND hwnd);

}
