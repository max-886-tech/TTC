#ifndef NOMINMAX
#define NOMINMAX
#endif

#include <windows.h>
#include <commdlg.h>
#include <shellscalingapi.h>
#include <shobjidl.h>   // IFileDialog
#include <filesystem>
#include <string>
#include <vector>
#include <fstream>
#include <sstream>
#include <cstring>

#pragma comment(lib, "Shcore.lib")
#pragma comment(lib, "Comdlg32.lib")
#pragma comment(lib, "Ole32.lib")
#pragma comment(lib, "Shell32.lib")

#include "../core/ttc_format.h"
#include "../core/crypto.h"

using namespace ttc::crypto;

// -----------------------------
// Helpers
// -----------------------------
static void EnableDpiAwareness() {
    SetProcessDpiAwareness(PROCESS_PER_MONITOR_DPI_AWARE);
}

static int DpiScale(HWND hwnd, int px) {
    UINT dpi = 96;
    HMODULE hUser32 = GetModuleHandleW(L"user32.dll");
    if (hUser32) {
        auto pGetDpiForWindow = (UINT(WINAPI*)(HWND))GetProcAddress(hUser32, "GetDpiForWindow");
        if (pGetDpiForWindow) dpi = pGetDpiForWindow(hwnd);
    }
    return MulDiv(px, (int)dpi, 96);
}

static std::string WideToUtf8(const std::wstring& ws) {
    if (ws.empty()) return {};
    int len = WideCharToMultiByte(CP_UTF8, 0, ws.c_str(), (int)ws.size(), nullptr, 0, nullptr, nullptr);
    std::string out(len, '\0');
    WideCharToMultiByte(CP_UTF8, 0, ws.c_str(), (int)ws.size(), out.data(), len, nullptr, nullptr);
    return out;
}

static std::wstring Utf8ToWide(const std::string& s) {
    if (s.empty()) return L"";
    int n = MultiByteToWideChar(CP_UTF8, 0, s.data(), (int)s.size(), nullptr, 0);
    std::wstring w(n, L'\0');
    MultiByteToWideChar(CP_UTF8, 0, s.data(), (int)s.size(), w.data(), n);
    return w;
}

static std::string JsonEscape(const std::string& s) {
    std::string out;
    out.reserve(s.size() + 16);
    for (char c : s) {
        switch (c) {
        case '\\': out += "\\\\"; break;
        case '"':  out += "\\\""; break;
        case '\b': out += "\\b"; break;
        case '\f': out += "\\f"; break;
        case '\n': out += "\\n"; break;
        case '\r': out += "\\r"; break;
        case '\t': out += "\\t"; break;
        default:
            if ((unsigned char)c < 0x20) {
                char buf[7];
                snprintf(buf, sizeof(buf), "\\u%04x", (unsigned char)c);
                out += buf;
            } else out += c;
        }
    }
    return out;
}

static bool ReadFileBin(const std::filesystem::path& path, std::vector<uint8_t>& out) {
    std::ifstream f(path, std::ios::binary);
    if (!f) return false;
    out.assign(std::istreambuf_iterator<char>(f), std::istreambuf_iterator<char>());
    return true;
}

static std::string HexFromBytes(const uint8_t* b, size_t n) {
    static const char* hex = "0123456789abcdef";
    std::string out;
    out.resize(n * 2);
    for (size_t i = 0; i < n; ++i) {
        out[i*2 + 0] = hex[(b[i] >> 4) & 0xF];
        out[i*2 + 1] = hex[(b[i] >> 0) & 0xF];
    }
    return out;
}

static std::string NewFileId() {
    uint8_t r[16];
    if (!SecureRandom(r, sizeof(r))) {
        for (int i = 0; i < 16; ++i) r[i] = (uint8_t)(i * 17);
    }
    return HexFromBytes(r, sizeof(r)); // 32 hex chars
}

static std::filesystem::path AutoOutPath(const std::filesystem::path& folder, const std::filesystem::path& pdfPath) {
    auto stem = pdfPath.stem().wstring();
    return folder / (stem + L".ttc");
}

static std::string AutoExamIdFromPdfName(const std::filesystem::path& pdfPath) {
    return WideToUtf8(pdfPath.stem().wstring());
}

static std::wstring GetText(HWND h) {
    int len = GetWindowTextLengthW(h);
    std::wstring s(len, L'\0');
    GetWindowTextW(h, s.data(), len + 1);
    return s;
}

static void SetText(HWND h, const std::wstring& s) {
    SetWindowTextW(h, s.c_str());
}

static void MsgInfo(HWND owner, const std::wstring& m) {
    MessageBoxW(owner, m.c_str(), L"TTC Converter", MB_OK | MB_ICONINFORMATION);
}

static void MsgErr(HWND owner, const std::wstring& m) {
    MessageBoxW(owner, m.c_str(), L"TTC Converter", MB_OK | MB_ICONERROR);
}

static bool IsChecked(HWND hChk) {
    return (SendMessageW(hChk, BM_GETCHECK, 0, 0) == BST_CHECKED);
}

static void SetChecked(HWND hChk, bool on) {
    SendMessageW(hChk, BM_SETCHECK, on ? BST_CHECKED : BST_UNCHECKED, 0);
}

// -----------------------------
// Folder picker (Browse Output Folder)
// -----------------------------
static bool BrowseFolder(HWND owner, const std::filesystem::path& initial, std::filesystem::path& outFolder) {
    outFolder.clear();

    IFileDialog* pfd = nullptr;
    HRESULT hr = CoCreateInstance(CLSID_FileOpenDialog, nullptr, CLSCTX_INPROC_SERVER, IID_PPV_ARGS(&pfd));
    if (FAILED(hr) || !pfd) return false;

    DWORD opts = 0;
    pfd->GetOptions(&opts);
    pfd->SetOptions(opts | FOS_PICKFOLDERS | FOS_FORCEFILESYSTEM | FOS_PATHMUSTEXIST);

    if (!initial.empty()) {
        IShellItem* psi = nullptr;
        if (SUCCEEDED(SHCreateItemFromParsingName(initial.c_str(), nullptr, IID_PPV_ARGS(&psi))) && psi) {
            pfd->SetFolder(psi);
            psi->Release();
        }
    }

    hr = pfd->Show(owner);
    if (FAILED(hr)) { pfd->Release(); return false; }

    IShellItem* result = nullptr;
    hr = pfd->GetResult(&result);
    if (FAILED(hr) || !result) { pfd->Release(); return false; }

    PWSTR psz = nullptr;
    hr = result->GetDisplayName(SIGDN_FILESYSPATH, &psz);
    if (SUCCEEDED(hr) && psz) {
        outFolder = std::filesystem::path(psz);
        CoTaskMemFree(psz);
    }

    result->Release();
    pfd->Release();
    return !outFolder.empty();
}

// -----------------------------
// TTC Convert core
// -----------------------------
static bool ConvertPdfToTtc(
    const std::filesystem::path& pdfPath,
    const std::filesystem::path& outPath,
    const std::string& examIdUtf8,
    std::string& outFileId,
    std::string& outErr
) {
    outErr.clear();
    outFileId.clear();

    std::vector<uint8_t> pdfData;
    if (!ReadFileBin(pdfPath, pdfData)) {
        outErr = "Failed to read PDF file.";
        return false;
    }

    // fixed content password (same as your CLI)
    const std::string contentPassword = "-ddG^|cE;8+Yp8&&";

    std::string fileId = NewFileId();
    outFileId = fileId;

    std::string metaJson =
        "{"
          "\"schema\":2,"
          "\"examId\":\"" + JsonEscape(examIdUtf8) + "\","
          "\"fileId\":\"" + JsonEscape(fileId) + "\","
          "\"user\":\"\","
          "\"expiryAbs\":\"\","
          "\"expiryRelHours\":0,"
          "\"watermark\":[]"
        "}";

    std::vector<uint8_t> metaBytes(metaJson.begin(), metaJson.end());

    ttc::format::TtcHeader header{};
    std::memcpy(header.magic, ttc::format::TTC_MAGIC, 4);
    header.version = ttc::format::TTC_VERSION;
    header.flags = 0;
    header.metadataSize = (uint32_t)metaBytes.size();
    header.payloadSize = 0;

    if (!SecureRandom(header.salt, SALT_LEN)) { outErr = "Failed to generate salt."; return false; }
    if (!SecureRandom(header.nonce, NONCE_LEN)) { outErr = "Failed to generate nonce."; return false; }

    uint8_t key[KEY_LEN];
    if (!DeriveKeyPBKDF2(contentPassword, header.salt, key)) {
        outErr = "Key derivation failed.";
        return false;
    }

    header.payloadSize = (uint64_t)pdfData.size();

    std::vector<uint8_t> aad(sizeof(ttc::format::TtcHeader) + metaBytes.size());
    std::memcpy(aad.data(), &header, sizeof(ttc::format::TtcHeader));
    std::memcpy(aad.data() + sizeof(ttc::format::TtcHeader), metaBytes.data(), metaBytes.size());

    std::vector<uint8_t> cipher;
    uint8_t tag[TAG_LEN]{};

    if (!EncryptAESGCM_AAD(pdfData, key, header.nonce, aad, cipher, tag)) {
        outErr = "Encryption failed.";
        return false;
    }

    std::ofstream out(outPath, std::ios::binary);
    if (!out) { outErr = "Failed to open output file for writing."; return false; }

    out.write((char*)&header, sizeof(header));
    out.write((char*)metaBytes.data(), (std::streamsize)metaBytes.size());
    out.write((char*)cipher.data(), (std::streamsize)cipher.size());
    out.write((char*)tag, TAG_LEN);

    if (!out.good()) { outErr = "Write failed."; return false; }
    return true;
}

// -----------------------------
// GUI
// -----------------------------
static const wchar_t* kWndClass = L"TTC_CONVERTER_GUI";

static constexpr int IDC_ED_PDF        = 2001;
static constexpr int IDC_BTN_PDF       = 2002;
static constexpr int IDC_ED_OUT        = 2003;
static constexpr int IDC_BTN_OUTFOLDER = 2004;
static constexpr int IDC_ED_EXAM       = 2005;
static constexpr int IDC_CHK_AUTOEXAM  = 2006;
static constexpr int IDC_BTN_GO        = 2007;
static constexpr int IDC_BTN_EXIT      = 2008;

static HWND g_edPdf  = nullptr;
static HWND g_edOut  = nullptr;
static HWND g_edExam = nullptr;
static HWND g_chkAutoExam = nullptr;
static HWND g_btnGo  = nullptr;

static std::filesystem::path g_pdfPath;
static std::filesystem::path g_outFolder; // folder only
static std::filesystem::path g_outPath;   // full file path

static bool BrowsePdf(HWND owner, std::filesystem::path& outPdf) {
    wchar_t file[MAX_PATH] = L"";
    OPENFILENAMEW ofn{};
    ofn.lStructSize = sizeof(ofn);
    ofn.hwndOwner = owner;
    ofn.lpstrFilter = L"PDF Files (*.pdf)\0*.pdf\0All Files (*.*)\0*.*\0";
    ofn.lpstrFile = file;
    ofn.nMaxFile = MAX_PATH;
    ofn.Flags = OFN_FILEMUSTEXIST | OFN_PATHMUSTEXIST;

    if (!GetOpenFileNameW(&ofn)) return false;
    outPdf = std::filesystem::path(file);
    return true;
}

static void RecalcOutputPath() {
    if (g_pdfPath.empty()) {
        g_outPath.clear();
        return;
    }
    if (g_outFolder.empty()) {
        g_outFolder = g_pdfPath.parent_path();
    }
    g_outPath = AutoOutPath(g_outFolder, g_pdfPath);
}

static void SyncUiState(HWND hwnd) {
    // output path
    RecalcOutputPath();
    SetText(g_edOut, g_outPath.empty() ? L"" : g_outPath.wstring());

    // exam checkbox behavior
    bool autoExam = IsChecked(g_chkAutoExam);
    EnableWindow(g_edExam, autoExam ? FALSE : TRUE);

    if (!g_pdfPath.empty() && autoExam) {
        SetText(g_edExam, Utf8ToWide(AutoExamIdFromPdfName(g_pdfPath)));
    }

    // Convert enabled only if PDF selected
    EnableWindow(g_btnGo, !g_pdfPath.empty());
    InvalidateRect(hwnd, nullptr, TRUE);
}

// -----------------------------
// Window proc
// -----------------------------
static LRESULT CALLBACK WndProc(HWND hwnd, UINT msg, WPARAM w, LPARAM l) {
    switch (msg) {
    case WM_CREATE: {
        int pad = DpiScale(hwnd, 12);
        int rowH = DpiScale(hwnd, 24);
        int btnH = DpiScale(hwnd, 28);
        int labelW = DpiScale(hwnd, 100);
        int wEd = DpiScale(hwnd, 320);
        int wBtn = DpiScale(hwnd, 120);

        int x = pad;
        int y = pad;

        // PDF row
        CreateWindowExW(0, L"STATIC", L"PDF:", WS_CHILD | WS_VISIBLE,
            x, y + 4, labelW, rowH, hwnd, nullptr, GetModuleHandleW(nullptr), nullptr);

        g_edPdf = CreateWindowExW(WS_EX_CLIENTEDGE, L"EDIT", L"",
            WS_CHILD | WS_VISIBLE | ES_AUTOHSCROLL | ES_READONLY,
            x + labelW, y, wEd, rowH, hwnd, (HMENU)(INT_PTR)IDC_ED_PDF, GetModuleHandleW(nullptr), nullptr);

        CreateWindowExW(0, L"BUTTON", L"Browse PDF",
            WS_CHILD | WS_VISIBLE | BS_PUSHBUTTON,
            x + labelW + wEd + pad, y, wBtn, btnH,
            hwnd, (HMENU)(INT_PTR)IDC_BTN_PDF, GetModuleHandleW(nullptr), nullptr);

        y += rowH + pad;

        // Output row (shows full output file path, but browse selects folder)
        CreateWindowExW(0, L"STATIC", L"Output:", WS_CHILD | WS_VISIBLE,
            x, y + 4, labelW, rowH, hwnd, nullptr, GetModuleHandleW(nullptr), nullptr);

        g_edOut = CreateWindowExW(WS_EX_CLIENTEDGE, L"EDIT", L"",
            WS_CHILD | WS_VISIBLE | ES_AUTOHSCROLL | ES_READONLY,
            x + labelW, y, wEd, rowH, hwnd, (HMENU)(INT_PTR)IDC_ED_OUT, GetModuleHandleW(nullptr), nullptr);

        CreateWindowExW(0, L"BUTTON", L"Browse Folder",
            WS_CHILD | WS_VISIBLE | BS_PUSHBUTTON,
            x + labelW + wEd + pad, y, wBtn, btnH,
            hwnd, (HMENU)(INT_PTR)IDC_BTN_OUTFOLDER, GetModuleHandleW(nullptr), nullptr);

        y += rowH + pad;

        // Exam row
        CreateWindowExW(0, L"STATIC", L"Dumps Code:", WS_CHILD | WS_VISIBLE,
            x, y + 4, labelW, rowH, hwnd, nullptr, GetModuleHandleW(nullptr), nullptr);

        g_edExam = CreateWindowExW(WS_EX_CLIENTEDGE, L"EDIT", L"",
            WS_CHILD | WS_VISIBLE | ES_AUTOHSCROLL,
            x + labelW, y, wEd, rowH, hwnd, (HMENU)(INT_PTR)IDC_ED_EXAM, GetModuleHandleW(nullptr), nullptr);

        y += rowH + DpiScale(hwnd, 6);

        // Checkbox under exam
        g_chkAutoExam = CreateWindowExW(0, L"BUTTON", L"Use PDF filename as Dumps Code",
            WS_CHILD | WS_VISIBLE | BS_AUTOCHECKBOX,
            x + labelW, y, wEd + wBtn, rowH,
            hwnd, (HMENU)(INT_PTR)IDC_CHK_AUTOEXAM, GetModuleHandleW(nullptr), nullptr);

        SetChecked(g_chkAutoExam, true); // default ON

        y += rowH + pad + DpiScale(hwnd, 4);

        // Buttons
        g_btnGo = CreateWindowExW(0, L"BUTTON", L"Convert",
            WS_CHILD | WS_VISIBLE | BS_DEFPUSHBUTTON,
            x + labelW, y, DpiScale(hwnd, 110), btnH,
            hwnd, (HMENU)(INT_PTR)IDC_BTN_GO, GetModuleHandleW(nullptr), nullptr);

        CreateWindowExW(0, L"BUTTON", L"Exit",
            WS_CHILD | WS_VISIBLE | BS_PUSHBUTTON,
            x + labelW + DpiScale(hwnd, 120), y, DpiScale(hwnd, 90), btnH,
            hwnd, (HMENU)(INT_PTR)IDC_BTN_EXIT, GetModuleHandleW(nullptr), nullptr);

        EnableWindow(g_btnGo, FALSE);
        EnableWindow(g_edExam, FALSE); // because checkbox ON initially
        return 0;
    }

    case WM_COMMAND: {
        int id = LOWORD(w);
        int code = HIWORD(w);

        if (id == IDC_BTN_PDF && code == BN_CLICKED) {
            std::filesystem::path p;
            if (BrowsePdf(hwnd, p)) {
                g_pdfPath = p;
                SetText(g_edPdf, g_pdfPath.wstring());

                // default out folder to pdf folder only if user hasn't chosen a folder yet
                if (g_outFolder.empty()) g_outFolder = g_pdfPath.parent_path();

                SyncUiState(hwnd);
            }
            return 0;
        }

        if (id == IDC_BTN_OUTFOLDER && code == BN_CLICKED) {
            std::filesystem::path initial = !g_outFolder.empty() ? g_outFolder :
                                            (!g_pdfPath.empty() ? g_pdfPath.parent_path() : std::filesystem::path());
            std::filesystem::path folder;
            if (BrowseFolder(hwnd, initial, folder)) {
                g_outFolder = folder;
                SyncUiState(hwnd);
            }
            return 0;
        }

        if (id == IDC_CHK_AUTOEXAM && code == BN_CLICKED) {
            SyncUiState(hwnd);
            return 0;
        }

        if (id == IDC_BTN_GO && code == BN_CLICKED) {
            if (g_pdfPath.empty()) {
                MsgErr(hwnd, L"Please select a PDF file.");
                return 0;
            }

            // output folder must exist
            if (g_outFolder.empty()) g_outFolder = g_pdfPath.parent_path();
            RecalcOutputPath();

            // exam code logic
            std::string examIdUtf8;
            if (IsChecked(g_chkAutoExam)) {
                examIdUtf8 = AutoExamIdFromPdfName(g_pdfPath);
            } else {
                std::wstring wExam = GetText(g_edExam);
                examIdUtf8 = WideToUtf8(wExam);
                if (examIdUtf8.empty()) {
                    MsgErr(hwnd, L"Please enter Dumps Code (or enable the checkbox).");
                    SetFocus(g_edExam);
                    return 0;
                }
            }

            EnableWindow(g_btnGo, FALSE);

            std::string fileId, err;
            bool ok = ConvertPdfToTtc(g_pdfPath, g_outPath, examIdUtf8, fileId, err);

            EnableWindow(g_btnGo, TRUE);

            if (!ok) {
                MsgErr(hwnd, Utf8ToWide(err));
                return 0;
            }

            std::wstring msg =
                L"TTC dumps file created:\n\n" + g_outPath.wstring() +
                L"\n\nDumps Code: " + Utf8ToWide(examIdUtf8) +
                L"\nFile ID: " + Utf8ToWide(fileId);

            MsgInfo(hwnd, msg);
            return 0;
        }

        if (id == IDC_BTN_EXIT && code == BN_CLICKED) {
            DestroyWindow(hwnd);
            return 0;
        }

        break;
    }

    case WM_CLOSE:
        DestroyWindow(hwnd);
        return 0;

    case WM_DESTROY:
        PostQuitMessage(0);
        return 0;
    }

    return DefWindowProcW(hwnd, msg, w, l);
}

int WINAPI wWinMain(HINSTANCE hInst, HINSTANCE, PWSTR, int cmdShow) {
    EnableDpiAwareness();

    // Needed for folder picker (IFileDialog)
    CoInitializeEx(nullptr, COINIT_APARTMENTTHREADED | COINIT_DISABLE_OLE1DDE);

    WNDCLASSW wc{};
    wc.lpfnWndProc = WndProc;
    wc.hInstance = hInst;
    wc.lpszClassName = L"TTC_CONVERTER_GUI";
    wc.hCursor = LoadCursor(nullptr, IDC_ARROW);
    wc.hbrBackground = (HBRUSH)(COLOR_WINDOW + 1);
    RegisterClassW(&wc);

    // small popup size
    HWND hwnd = CreateWindowExW(
        WS_EX_DLGMODALFRAME,
        wc.lpszClassName,
        L"TTC Converter",
        WS_CAPTION | WS_SYSMENU | WS_MINIMIZEBOX,
        CW_USEDEFAULT, CW_USEDEFAULT, 750, 300,
        nullptr, nullptr, hInst, nullptr
    );

    if (!hwnd) {
        CoUninitialize();
        return 1;
    }

    ShowWindow(hwnd, cmdShow);
    UpdateWindow(hwnd);

    MSG msg{};
    while (GetMessageW(&msg, nullptr, 0, 0)) {
        TranslateMessage(&msg);
        DispatchMessageW(&msg);
    }

    CoUninitialize();
    return 0;
}
