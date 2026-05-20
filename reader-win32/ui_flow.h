#pragma once
#include <windows.h>

void StartIndeterminateProgress(HWND hwnd);
void StopIndeterminateProgress(HWND hwnd);
LRESULT HandleAppProgress(HWND hwnd, WPARAM wParam, LPARAM lParam);
