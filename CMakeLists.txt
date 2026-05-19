cmake_minimum_required(VERSION 3.21)
project(truecerts_ttc_win32 LANGUAGES CXX)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)

# For a small reader binary, prefer the dynamic MSVC runtime.
# Debug  -> MultiThreadedDebugDLL
# Release-> MultiThreadedDLL
set(CMAKE_MSVC_RUNTIME_LIBRARY "MultiThreaded$<$<CONFIG:Debug>:Debug>DLL")

# -----------------------------
# Core library
# -----------------------------
add_library(ttc-core
    core/crypto.cpp
    core/ttc_format.cpp
    core/ttc_decrypt.cpp
)

target_include_directories(ttc-core
    PUBLIC
        ${CMAKE_CURRENT_SOURCE_DIR}/core
        ${CMAKE_CURRENT_SOURCE_DIR}/third_party
)

target_link_libraries(ttc-core bcrypt)

# -----------------------------
# Win32 Reader
# -----------------------------
add_executable(ttc-reader
    reader-win32/main.cpp
    reader-win32/pdf_viewer.cpp
    reader-win32/watermark.cpp
    reader-win32/license_online.cpp
)

set_target_properties(ttc-reader PROPERTIES
    WIN32_EXECUTABLE TRUE
)

target_include_directories(ttc-reader
    PRIVATE
        ${CMAKE_CURRENT_SOURCE_DIR}/third_party/pdfium/include
)

target_sources(ttc-reader PRIVATE
    reader-win32/resources/app.rc
)

target_link_libraries(ttc-reader
    PRIVATE
        ttc-core
        ${CMAKE_CURRENT_SOURCE_DIR}/third_party/pdfium/lib/pdfium.dll.lib

        gdi32
        user32
        comdlg32
        crypt32
        shell32

        iphlpapi
        advapi32
        gdiplus
        winhttp
        shcore
        comctl32
)

# Size-focused MSVC options (Release builds)
if (MSVC)
    target_compile_options(ttc-reader PRIVATE
        $<$<CONFIG:Release>:/Os /GL /Gy>
    )
    target_link_options(ttc-reader PRIVATE
        $<$<CONFIG:Release>:/OPT:REF /OPT:ICF>
    )
endif()

# -----------------------------
# Converter (GUI popup)
# -----------------------------
add_executable(ttc-converter WIN32
    converter/main.cpp
)

target_link_libraries(ttc-converter
    PRIVATE
        ttc-core
        gdi32
        user32
        comdlg32
        shell32
        ole32
        shcore
)

if (MSVC)
    target_compile_options(ttc-converter PRIVATE
        $<$<CONFIG:Release>:/Os /GL /Gy>
    )
    target_link_options(ttc-converter PRIVATE
        $<$<CONFIG:Release>:/OPT:REF /OPT:ICF>
    )
endif()

# -----------------------------
# MSI (WiX) packaging for ttc-reader
# Installs to: C:\TheTrueCerts.com\
# Registers .ttc association
#
# REQUIREMENTS:
# - WiX Toolset installed (candle.exe, light.exe available in PATH)
# - Update installer/TrueCertsReader.wxs UpgradeCode GUID
# - Adjust pdfium.dll path below if your runtime DLL is elsewhere
# -----------------------------
set(MSI_STAGE_DIR "${CMAKE_BINARY_DIR}/msi_stage")
set(MSI_WXS "${CMAKE_CURRENT_SOURCE_DIR}/installer/TrueCertsReader.wxs")
set(MSI_OUT "${CMAKE_BINARY_DIR}/TrueCertsReader.msi")

add_custom_target(ttc-reader-msi
  DEPENDS ttc-reader

  COMMAND ${CMAKE_COMMAND} -E make_directory "${MSI_STAGE_DIR}"
  COMMAND ${CMAKE_COMMAND} -E copy_if_different "$<TARGET_FILE:ttc-reader>" "${MSI_STAGE_DIR}/ttc-reader.exe"
  COMMAND ${CMAKE_COMMAND} -E copy_if_different "${CMAKE_CURRENT_SOURCE_DIR}/third_party/pdfium/bin/pdfium.dll" "${MSI_STAGE_DIR}/pdfium.dll"

  COMMAND candle.exe -nologo
  -dSourceDir="${MSI_STAGE_DIR}"
  -dRepoDir="${CMAKE_CURRENT_SOURCE_DIR}"
  -out "${CMAKE_BINARY_DIR}/TrueCertsReader.wixobj"
  "${MSI_WXS}"

  COMMAND light.exe  -nologo -ext WixUIExtension -out "${MSI_OUT}" "${CMAKE_BINARY_DIR}/TrueCertsReader.wixobj"

  BYPRODUCTS "${MSI_OUT}"
  COMMENT "Building MSI installer: ${MSI_OUT}"
)
