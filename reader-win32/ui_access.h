#pragma once
#include <windows.h>
#include <string>

// Access-code (EnterCode) screen
namespace ui_access {

void Create(HWND hwnd);
void Layout(HWND hwnd);
void Show(bool show);

std::wstring GetCode();
void ClearCode();
void FocusCode();
void SetNextEnabled(bool enabled);

}
