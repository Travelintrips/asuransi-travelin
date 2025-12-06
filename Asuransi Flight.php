<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <title>Asuransi Kecelakaan Diri Inflight</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script type="module">
document.addEventListener("DOMContentLoaded", async function () {

    const preview       = document.getElementById("preview");
    const placeholder   = document.querySelector(".placeholder-text");
    const menu          = document.getElementById("scannerMenu");
    const cameraChoice  = document.getElementById("cameraChoice");
    const chooseScan    = document.getElementById("chooseScan");
    const chooseUpload  = document.getElementById("chooseUpload");
    const closeMenu     = document.getElementById("closeMenu");
    const frontCam      = document.getElementById("frontCam");
    const backCam       = document.getElementById("backCam");
    const closeCamChoice= document.getElementById("closeCamChoice");
    const stopInside    = document.getElementById("stopCameraInside");
    const uploadInput   = document.getElementById("uploadInput");
    const resultElem    = document.getElementById("result");
    const barcodeBox    = document.getElementById("barcodeBox");

    let currentStream = null;
    let selectedFacing = "environment";
    let codeReader = null;
    let isDecoding = false;

    // Load ZXing dari CDN yang tepat
    async function loadZXing() {
        if (!window.ZXing) {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/@zxing/library@latest/umd/index.min.js';
            script.onload = () => console.log("ZXing loaded");
            document.head.appendChild(script);
            
            // Tunggu hingga library loaded
            return new Promise(resolve => {
                const checkZXing = setInterval(() => {
                    if (window.ZXing) {
                        clearInterval(checkZXing);
                        codeReader = new window.ZXing.BrowserMultiFormatReader();
                        resolve(codeReader);
                    }
                }, 100);
            });
        }
        return codeReader;
    }

    function isCameraActive() {
        return currentStream !== null;
    }

    function stopCameraStream() {
        isDecoding = false;
        
        if (codeReader) {
            try { codeReader.stopContinuousDecode(); } catch(e){}
            try { codeReader.reset(); } catch(e){}
        }

        if (currentStream) {
            currentStream.getTracks().forEach(t => t.stop());
            currentStream = null;
        }

        preview.srcObject = null;
        preview.style.display = "none";
        placeholder.style.display = "block";
        stopInside.style.display = "none";
        laserLine.style.opacity = 0;
    }

    async function startCamera() {
        stopCameraStream();

        try {
            // Minta permission kamera
            const permission = await navigator.permissions.query({ name: 'camera' });
            if (permission.state === 'denied') {
                alert("⚠️ Akses kamera ditolak. Periksa pengaturan browser Anda.");
                return;
            }

            placeholder.style.display = "none";
            preview.style.display = "block";
            stopInside.style.display = "block";
            laserLine.style.opacity = 1;

            currentStream = await navigator.mediaDevices.getUserMedia({
                video: { 
                    facingMode: selectedFacing,
                    width: { ideal: 1280 },
                    height: { ideal: 720 }
                },
                audio: false
            });

            preview.srcObject = currentStream;

            // PENTING: Tunggu video fully loaded
            await new Promise((resolve) => {
                preview.onloadedmetadata = () => {
                    preview.play();
                    resolve();
                };
            });

            // Mulai decode setelah video ready
            await loadZXing();
            isDecoding = true;

            decodeBarcode();

        } catch (error) {
            console.error("Camera error:", error);
            let msg = error.name;
            if (error.name === "NotAllowedError") {
                msg = "❌ Izin kamera ditolak";
            } else if (error.name === "NotFoundError") {
                msg = "❌ Kamera tidak ditemukan";
            } else if (error.name === "NotReadableError") {
                msg = "❌ Kamera sedang digunakan aplikasi lain";
            }
            alert(msg);
            stopCameraStream();
            
        }
    }
    
    // =============================
// UNIVERSAL BCBP PARSER (FINAL)
// =============================

function parseInflightData(raw) {
    raw = raw.replace(/\s+/g, " ").trim();

    let data = {
        nama: "",
        kode: "",
        tanggal: ""
    };

  
    if (raw.includes("M1")) {
        // Extract Nama → setelah M1 sampai airport code 3 huruf pertama
        let namaMatch = raw.match(/M1([A-Z\/\s]+?)(?=\s[A-Z]{3}\b)/);
        if (namaMatch) data.nama = namaMatch[1].trim();
    }

    if (!data.nama) {
        let fallbackNama = raw.match(/^([A-Z\s\/]+?)\s+[A-Z]{2,3}\s*\d{3,4}\b/);
        if (fallbackNama) data.nama = fallbackNama[1].trim();
    }

    if (!data.nama) {
        let compactNama = raw.match(/M1([A-Z\/]+?)(?=[A-Z]{2}\d{3,4})/);
        if (compactNama) data.nama = compactNama[1].replace(/\//g, " ").trim();
    }

    // Normalisasi nama → hilangkan "MR", "MRS", "MS", "MISS"
    data.nama = data.nama
        .replace(/\bMR\b|\bMRS\b|\bMS\b|\bMISS\b/gi, "")
        .trim();

    let kodeMatch = raw.match(/\b([A-Z]{2,3})\s*0?(\d{2,4})\b/);
    if (kodeMatch) {
        data.kode = kodeMatch[1] + kodeMatch[2];
    }

    // --- Cek Julian Date dulu ---
    let julian = raw.match(/\b(\d{3})\b/);
    if (julian) {
        data.tanggal = convertJulian(julian[1]);
    }

    // --- Cek format DDMMMYY (fallback) ---
    let dmy = raw.match(/\b(\d{2}[A-Z]{3}\d{2})\b/);
    if (dmy) {
        data.tanggal = convertDDMMMYY(dmy[1]); 
    }

    return data;
}


// Konversi Julian → YYYY-MM-DD
function convertJulian(j) {
    const year = new Date().getFullYear();
    const date = new Date(year, 0);
    date.setDate(parseInt(j));
    return date.toISOString().slice(0, 10);
}

// Konversi 11NOV25 → YYYY-MM-DD
function convertDDMMMYY(str) {
    const months = {
        JAN: 0, FEB: 1, MAR: 2, APR: 3, MAY: 4, JUN: 5,
        JUL: 6, AUG: 7, SEP: 8, OCT: 9, NOV: 10, DEC: 11
    };

    const day = parseInt(str.slice(0, 2));
    const mon = months[str.slice(2, 5)];
    let year = parseInt(str.slice(5, 7));

    // Tahun 25 → 2025
    year = 2000 + year;

    const d = new Date(year, mon, day);
    return d.toISOString().slice(0, 10);
}

let lastScanId = null;

async function decodeBarcode() {
    if (!isDecoding) return;

    try {
        const result = await codeReader.decodeOnceFromVideoDevice(undefined, preview);

        if (result) {
            stopCameraStream();
            let raw = result.text.trim();

            // Tampilkan hasil scan
            document.getElementById("scanResultBox").style.display = "block";
            document.getElementById("hasilRaw").innerText = raw;

            // Simpan ke database
            saveRawBarcode(raw);
        }

    } catch (err) {
        if (isDecoding) requestAnimationFrame(decodeBarcode);
    }
}

function saveRawBarcode(raw) {
    fetch("/wp-json/scan/v1/save", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ barcode: raw })
    })
    .then(res => res.json())
    .then(data => {
        console.log("API RESPONSE:", data);

        if (data.success) {
            lastScanId = data.id;

            let topBtn = document.getElementById("topContinue");
            topBtn.classList.add("active");
            topBtn.style.pointerEvents = "auto";

            topBtn.onclick = () => {
                window.location.href = "/inflight-data/?scan_id=" + lastScanId;
            };
        }
    })
    .catch(err => {
        console.error("Save error:", err);
    });
}

    // EVENT LISTENERS
    barcodeBox.onclick = (e) => {
        if (!isCameraActive()) {
            e.stopPropagation();
            menu.style.display = "flex";
        }
    };

    closeMenu.onclick = () => {
        menu.style.display = "none";
    };

    chooseScan.onclick = (e) => {
        e.stopPropagation();
        menu.style.display = "none";
        cameraChoice.style.display = "flex";
    };

    frontCam.onclick = async () => {
        selectedFacing = "user";
        cameraChoice.style.display = "none";
        await startCamera();
    };

    backCam.onclick = async () => {
        selectedFacing = "environment";
        cameraChoice.style.display = "none";
        await startCamera();
    };

    closeCamChoice.onclick = () => {
        cameraChoice.style.display = "none";
    };

    stopInside.onclick = (e) => {
        e.stopPropagation();
        stopCameraStream();
    };

    chooseUpload.onclick = (e) => {
        e.stopPropagation();
        menu.style.display = "none";
        uploadInput.click();
    };

    uploadInput.onchange = async (e) => {
    let file = e.target.files[0];
    if (!file) return;

    stopCameraStream(); // pastikan kamera mati
    await loadZXing();

    const img = new Image();
    img.src = URL.createObjectURL(file);

    img.onload = async () => {
        try {
            const result = await codeReader.decodeFromImage(img);
            let raw = result.text.trim();

            console.log("Barcode from image:", raw);

            // 🔥 Tampilkan hasil ke user
            document.getElementById("scanResultBox").style.display = "block";
            document.getElementById("hasilRaw").innerText = raw;

            // 🔥 Simpan ke database
            saveRawBarcode(raw);

        } catch (err) {
            alert("❌ Gambar tidak mengandung barcode yang valid");
        }
    };

    img.onerror = () => {
        alert("❌ Gagal memproses gambar");
    };
};


});
</script>

  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    }

    /* Frame Desktop */
    .container {
      width: 100%;
      max-width: 1200px;
      background: #ffffff;
      border-radius: 18px;
      box-shadow: 0 0 10px rgba(0,0,0,0.15);
      overflow: hidden;
      border: 1px solid #e0e0e0;
    }

    .top-bar {
      display: flex;
      align-items: flex-start;;
      padding: 2px 16px;
      border-bottom: 1px solid #e5e5e5;
      font-size: 42px;
      font-weight: 600;
      background: white;
      gap: 20px;
    }

    .back-arrow {
  font-size: 50px;
  font-weight: 600;
  cursor: pointer;
  line-height: 1;
  padding-bottom: 8px;
    }


    .header {
      padding: 10px 24px;
      border-bottom: 1px solid #e5e5e5;
      background: #F0F0F0;
      color: black;
    }
    
    .header-2 {
      text-align: center;
      padding-bottom: 10px;
      background: #ffffff;
      font-size: 16px;
      font-weight: 700;
      color: black;
    }

    .header-top {
      display: flex;
      gap: 16px;
      margin-bottom: 0px;
    }

    .header-top .chubb {
      font-weight: 700;
      letter-spacing: 0.10em;
      color: #232323;
      font-family: Tachyon Regular;
      
    }

    .header-top .syariah {
      font-weight: 700;
      color: #232323;
      
    }
    
    /*titel Asuransi*/
    .header-title {
      font-size: 15px;
      font-weight: 600;
      color: black;
      
      
    }

    /* CONTENT AREA – DESKTOP */
    .content-wrapper {
      background: #00a1b3;
      padding: 36px 26px;
    }

    .card {
      background: #ffffff;
      border-radius: 18px;
      padding: 28px 28px 36px;
      margin: 0 auto;
      box-shadow: 0 3px 4px rgba(0,0,0,0.1);
      max-width: 820px;
    }

    .card-title {
      text-align: center;
      font-size: 22px;
      font-weight: 600;
      margin-bottom: 24px;
    }

    .section {
      margin-bottom: 20px;
      font-size: 15px;
      line-height: 1.55;
      color: #333;
    }

    .section-title {
      font-weight: 700;
      color: #0086a0;
      margin-bottom: 6px;
      font-size: 17px;
    }

    .sub-title {
      font-weight: 700;
      margin: 6px 0;
      font-size: 15px;
    }

    .section p {
      margin-bottom: 6px;
      text-align: justify;
    }

    .section ul {
      margin-left: 20px;
    }

    .divider {
      border-top: 1px solid #ddd;
      margin: 12px 0 18px;
    }
    

/* === SCANNER AREA === */
.barcode-box {
    width: 220px;
    height: 220px;
    background: #000;
    margin: 0 auto 20px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    position: relative;
    overflow: hidden;
    z-index: 10;
}

.placeholder-text {
    color: #fff;
    opacity: 0.7;
    font-size: 14px;
    pointer-events: none;
}

/* Popup menu */
.scanner-menu {
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.55);
        display: none;
        justify-content: center;
        align-items: center;
        z-index: 999999 !important;
        pointer-events: auto; /* pastikan menerima klik/touch */
        -webkit-tap-highlight-color: transparent;
    }

.menu-box {
        background: #fff;
        padding: 20px;
        border-radius: 14px;
        width: 260px;
        text-align: center;
        pointer-events: auto;
    }

.menu-box button {
        width: 100%;
        padding: 12px;
        margin: 8px 0;
        border: none;
        border-radius: 10px;
        cursor: pointer;
        font-size: 15px;
        background: #00a1b3;
        color: #fff;
        touch-action: manipulation;
    }

.close-btn {
    background: #777 !important;
}

/* Camera preview */
#preview {
    width: 220px;
    height: 220px;
    object-fit: cover;
    border-radius: 12px;
    display: none;
    margin: 10px auto;
}

.scan-result {
    text-align: center;
    font-size: 16px;
    font-weight: 600;
}

.stop-inside {
    display: none;
    position: absolute;
    bottom: 8px;
    background: rgba(0,0,0,0.6);
    color: #fff;
    border: none;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 14px;
    cursor: pointer;
    z-index: 20;
}

#topContinue.active {
    opacity: 1 !important;
    pointer-events: auto !important;
    cursor: pointer;
    color: #00a1b3;
    font-weight: 600;
}


@media (max-width:768px) {
    .barcode-box, #preview { width: 160px; height: 160px; }
}

/* LASER SCAN LINE */
#laserLine {
    position: absolute;
    top: 50%;
    left: 50%;
    width: 80%;
    height: 2px;
    background: red;
    transform: translate(-50%, -50%);
    box-shadow: 0 0 10px red;
    opacity: 0;
    animation: laserMove 1.4s infinite alternate ease-in-out;
    z-index: 50;
}

/* ANIMASI LASER */
@keyframes laserMove {
    from {
        top: 30%;
    }
    to {
        top: 70%;
    }
}

    /* RESPONSIVE MOBILE */
    @media (max-width: 768px) {
      .container {
        border-radius: 20px;
        .top-bar { font-size: 22px; padding: 10px; gap: 30px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .back-arrow { font-size: 54px; }
      }
      
  .top-bar {
  display: flex;
  justify-content: flex-start;
  align-items: center;
  gap: 12px;
  font-size: 20px;
}

/* MOBILE: tulisan Lanjutkan pindah ke kanan */
@media (max-width: 768px) {
  .top-bar {
    justify-content: space-between;
  }
}


      .card {
        padding: 18px;
      }
      .card-title {
        font-size: 17px;
      }
      .barcode-box {
        width: 160px;
        height: 160px;
      }
      .section {
        font-size: 12px;
      }
    }
  </style>
</head>
<body>
    
    

  <div class="container">

    <!-- TOP BAR -->
    <div class="top-bar">
    <span class="back-arrow" onclick="window.location.href='https://travelintrips.co.id/';">&#8592;</span>
    <span id="topContinue" style="opacity:0.3; pointer-events:none; cursor:not-allowed;">
        Lanjutkan
    </span>
</div>


    <!-- HEADER -->
    <div class="header">
      <div class="header-top">
        <span class="chubb">CHUBB</span>
        <span class="syariah">Chubb Syariah</span>
      </div>
      <div class="header-title">Asuransi Kecelakaan Diri Inflight</div>
    </div>

    <!-- BG TOSCA -->
    <div class="content-wrapper">
      <div class="card">
    <div class="header-2">
        <div class="header-title-2">Scan Barcode Booking Flight</div>
    </div>      
        
       <div class="barcode-box" id="barcodeBox">
    <div class="placeholder-text">Klik untuk Scan</div>
    <div id="laserLine"></div>

    <!-- Camera Preview -->
    <video id="preview" autoplay playsinline></video>

    <!-- Tombol Stop Kamera -->
    <button id="stopCameraInside" class="stop-inside">⛔ Stop Kamera</button>
</div>

<!-- Pop-up Menu Utama -->
<div id="scannerMenu" class="scanner-menu">
    <div class="menu-box">
        <button id="chooseScan" type="button">📷 Scan Barcode</button>
        <button id="chooseUpload" type="button">🖼 Upload Gambar</button>
        <button id="closeMenu" class="close-btn" type="button">Tutup</button>
    </div>
</div>

<!-- Pop-up Pilihan Kamera (BARU) -->
<div id="cameraChoice" class="scanner-menu">
    <div class="menu-box">
        <button id="frontCam" type="button">📱 Kamera Depan</button>
        <button id="backCam" type="button">📷 Kamera Belakang</button>
        <button id="closeCamChoice" class="close-btn" type="button">Tutup</button>
    </div>
</div>

<!-- Upload input -->
<input type="file" id="uploadInput" accept="image/*" style="display:none;">
<div id="result" class="scan-result"></div>

<!-- HASIL SCAN MUNCUL DI SINI -->
<div id="scanResultBox" style="margin-top:20px; padding:15px; background:white; border-radius:10px; display:none; border:1px solid #ddd;">
    <h3 style="margin-top:0;">Hasil Scan:</h3>
    <p id="hasilRaw" style="font-weight:bold; color:#333;"></p>
</div>


        <!-- MANFAAT -->
        <div class="section">
          <div class="section-title">Manfaat Asuransi</div>

          <div class="sub-title">Kematian & Cacat Akibat Kecelakaan</div>
          <p>
            Memberikan santunan kepada Peserta terhadap kematian dan cacat tetap yang disebabkan oleh kejadian
            kecelakaan yang bersifat kekerasan, eksternal, dan terlihat, serta terjadi selama periode yang dijamin.
          </p>

          <p class="sub-title">• Santunan Kematian Karena Kecelakaan</p>
          <p>
            Jika Tertanggung meninggal dunia akibat kecelakaan selama periode inflight,
            ahli waris akan menerima santunan sebesar Rp150.000.000.
          </p>

          <p class="sub-title">• Santunan Cacat Tetap Karena Kecelakaan</p>
          <p>
            Jika Tertanggung mengalami cacat tetap total atau sebagian akibat kecelakaan,
            akan diberikan santunan hingga Rp150.000.000.
          </p>

          <p class="sub-title">Penggantian Biaya Medis</p>
          <p>Menggantikan biaya medis akibat kecelakaan hingga Rp15.000.000.</p>
        </div>

        <div class="divider"></div>

        <!-- PENGECUALIAN -->
        <div class="section">
          <div class="sub-title">Pengecualian</div>
          <p>Berikut contoh kondisi yang tidak dijamin dalam polis:</p>
          <ul>
            <li>Tindakan sengaja melukai diri sendiri atau percobaan bunuh diri.</li>
            <li>Pengaruh alkohol atau obat-obatan terlarang.</li>
            <li>Balap, olahraga ekstrem, aktivitas militer.</li>
          </ul>
        </div>

        <div class="divider"></div>

        <!-- SYARAT -->
        <div class="section">
          <div class="section-title">Syarat & Ketentuan</div>
          <ul>
            <li>Peserta wajib mengisi data dengan benar.</li>
            <li>Perlindungan berlaku sesuai periode penerbangan.</li>
            <li>Tidak dapat dipindahtangankan tanpa persetujuan.</li>
          </ul>
        </div>

        <div class="divider"></div>

        <!-- TATA CARA KLAIM -->
        <div class="section">
          <div class="section-title">Tata Cara Klaim</div>
          <ul>
            <li>Laporan kejadian maks. 5×24 jam.</li>
            <li>Melampirkan tiket, boarding pass, dan identitas.</li>
            <li>Klaim dapat diajukan melalui layanan pelanggan.</li>
          </ul>
        </div>

      </div>
    </div>

  </div>
</body>
</html>
