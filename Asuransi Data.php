<script>
let scan_id = null;

document.addEventListener("DOMContentLoaded", function () {
    const urlParams = new URLSearchParams(window.location.search);
    scan_id = urlParams.get("scan_id");

    if (!scan_id) {
        alert("Scan ID tidak ditemukan. Silakan ulangi proses scan.");
        return;
    }

    console.log("SCAN ID:", scan_id);
});

function goToStep3() {
    // Validasi form - pastikan semua field terisi
    const namaPeserta = document.getElementById("namaPeserta").value.trim();
    const kodePenerbangan = document.getElementById("kodePenerbangan").value.trim();
    const tanggalPenerbangan = document.getElementById("tanggalPenerbangan").value.trim();
    const email = document.getElementById("email").value.trim();
    const teleponPeserta = document.getElementById("teleponPeserta").value.trim();
    const namaAnggota = document.getElementById("namaAnggota").value.trim();
    const teleponAnggota = document.getElementById("teleponAnggota").value.trim();

    // Cek field yang kosong
    const emptyFields = [];
    if (!namaPeserta) emptyFields.push("Nama Peserta");
    if (!kodePenerbangan) emptyFields.push("Kode Penerbangan");
    if (!tanggalPenerbangan) emptyFields.push("Tanggal Penerbangan");
    if (!email) emptyFields.push("Email");
    if (!teleponPeserta) emptyFields.push("Nomor Telepon Peserta");
    if (!namaAnggota) emptyFields.push("Nama Anggota Keluarga");
    if (!teleponAnggota) emptyFields.push("Nomor Telepon Anggota Keluarga");

    // Jika ada field kosong, tampilkan peringatan
    if (emptyFields.length > 0) {
        alert("Mohon lengkapi data berikut:\n\n• " + emptyFields.join("\n• "));
        return;
    }

    // Jika semua terisi, lanjutkan submit
    const payload = {
        scan_id: scan_id,
        namaPeserta: namaPeserta,
        kodePenerbangan: kodePenerbangan,
        tanggalPenerbangan: tanggalPenerbangan,
        teleponPeserta: teleponPeserta,
        namaAnggota: namaAnggota,
        email: email,
        teleponAnggota: teleponAnggota
    };

    fetch("/wp-json/scan/v1/insert-details", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert("Data berhasil disimpan!");
            showCustomDialog();
        }
    });
}

function showCustomDialog() {
    const modal = document.createElement("div");
    modal.id = "customModal";
    modal.innerHTML = `
        <div class="modal-overlay">
            <div class="modal-box">
                <p class="modal-text">Isi data penumpang lain?</p>
                <p class="modal-sub">Ya untuk isi data lainnya</p>
<p class="modal-sub">Lanjutkan untuk halaman berikutnya</p>
                <div class="modal-buttons">
                    <button class="btn-ya" onclick="resetForm()">Ya</button>
                    <button class="btn-lanjut" onclick="goToNextStep()">Lanjut</button>
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
}

function resetForm() {
    document.getElementById("namaPeserta").value = "";
    //document.getElementById("kodePenerbangan").value = "";
    //document.getElementById("tanggalPenerbangan").value = "";
   // document.getElementById("teleponPeserta").value = "";
    document.getElementById("namaAnggota").value = "";
    document.getElementById("email").value = "";
    document.getElementById("teleponAnggota").value = "";
    
    const modal = document.getElementById("customModal");
    if (modal) modal.remove();
}

function goToNextStep() {
    const modal = document.getElementById("customModal");
    if (modal) modal.remove();
    window.location.href = "/inflight-benefit/?scan_id=" + scan_id;
}


</script>


<style>
* {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
  font-family: font-family:  Roboto, sans-serif;
}

/* FRAME */
.container {
  width: 100%;
  max-width: 1200px;
  background: #ffffff;
  border-radius: 18px;
  box-shadow: 0 0 10px rgba(0,0,0,0.15);
  overflow: hidden;
  border: 1px solid #e0e0e0;
}

/* TOP BAR */
.top-bar {
  display: flex;
  flex: 1;
  align-items: flex-start;
  padding: 2px 16px;
  border-bottom: 1px solid #e5e5e5;
  font-size: 42px;
  font-weight: 600;
  background: white;
  gap: 400px;
}

.back-arrow {
  font-size: 48px;
  font-weight: 600;
  cursor: pointer;
  line-height: 1;
  padding-bottom: 12px;
  
}

/* HEADER CHUBB */
.header {
  padding: 10px 24px;
  border-bottom: 1px solid #e5e5e5;
  background: #F0F0F0;
  color: black;
}

.header-top {
  display: flex;
  gap: 16px;
}

.chubb {
  font-weight: 700;
  letter-spacing: 0.10em;
}

.syariah {
  font-weight: 700;
}

.header-title {
  font-size: 15px;
  font-weight: 600;
}

/* CONTENT WRAPPER TOSCA */
.content-wrapper {
  background: #00a1b3;
  padding: 26px 26px;
}

/* CARD WHITE */
.card {
  background: #ffffff;
  border-radius: 18px;
  padding: 28px;
  box-shadow: 0 3px 4px rgba(0,0,0,0.1);
  max-width: 820px;
  margin: 0 auto;
}

/* SECTION TITLES */
.section-title {
  font-size: 15px;
  font-weight: 700;
  margin-bottom: 12px;
  color: #222;
}

/* BLUE LABEL */
.label-small {
  color: #0b8ebc;
  font-size: 13px;
  font-style: italic;
  margin-bottom: 4px;
  display: block;
}

/* INPUT FIELDS */
input {
  width: 100%;
  background: #E6E6E6;
  border: none;
  border-radius: 6px;
  padding: 12px;
  font-size: 14px;
  margin-bottom: 20px;
}

input:focus {
  outline: 2px solid #00a1b3;
  background: #fff;
}

/* SUBMIT BUTTON */
.submit-btn {
  background: #ff9e9e;
  border: none;
  padding: 10px 26px;
  border-radius: 10px;
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
}

.submit-btn:hover {
  background: #ff7c7c;
}

/* MODAL STYLES */
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.modal-box {
  background: #ffffff;
  border-radius: 12px;
  padding: 30px;
  box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);
  text-align: center;
  max-width: 350px;
  width: 90%;
}

.modal-sub {
    margin: 4px 0;
    font-size: 14px;
    color: #4a4a4a;
    text-align: center;
    line-height: 1.4;
}


.modal-text {
  font-size: 16px;
  font-weight: 600;
  color: #222;
  margin-bottom: 24px;
}

.modal-buttons {
  display: flex;
  gap: 12px;
  justify-content: center;
  padding-top: 20px;
}

.btn-ya, .btn-lanjut {
  padding: 10px 24px;
  border: none;
  border-radius: 6px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.3s;
}

.btn-ya {
  background: #00a1b3;
  color: white;
}

.btn-ya:hover {
  background: #008a9e;
}

.btn-lanjut {
  background: #ff9e9e;
  color: white;
}

.btn-lanjut:hover {
  background: #ff7c7c;
}

/* RESPONSIVE */
@media (max-width: 768px) {
  .top-bar { font-size: 28px; padding: 10px; gap: 30px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; align-items: center; }
  .back-arrow { font-size: 54px; }
  .card { padding: 20px; }
}
</style>

<div class="container">

  <!-- TOP BAR -->
  <div class="top-bar">
    <span class="back-arrow" onclick="history.back()">←</span> Isi Data
  </div>

  <!-- HEADER -->
  <div class="header">
    <div class="header-top">
      <span class="chubb">CHUBB</span>
      <span class="syariah">Chubb Syariah</span>
    </div>
    <div class="header-title">Asuransi Kecelakaan Diri Inflight</div>
  </div>

  <!-- CONTENT -->
  <div class="content-wrapper">
  <div class="card">

    <!-- TITLE -->
    <div class="section-title">Informasi Data Peserta:</div>

    <label class="label-small">Nama Peserta</label>
    <input type="text" id="namaPeserta">

    <label class="label-small">Kode Penerbangan</label>
    <input type="text" id="kodePenerbangan">

    <label class="label-small">Tanggal Penerbangan</label>
    <input type="date" id="tanggalPenerbangan">
    
    <label class="label-small">Email</label>
    <input type="text" id="email">

    <div class="section-title" style="margin-top:18px;">Mohon Diisi Informasi Berikut:</div>

    <label class="label-small">Nomor Telepon Peserta</label>
    <input type="text" id="teleponPeserta">

    <label class="label-small">Nama Anggota Keluarga</label>
    <input type="text" id="namaAnggota">

    <label class="label-small">Nomor Telepon Anggota Keluarga</label>
    <input type="text" id="teleponAnggota">
    
    <div style="margin-top:10px; padding:10px; background:#fff3cd; border:1px solid #ffeeba; color:#856404; border-radius:4px;">
        <strong>Note:</strong> Email penerima notifikasi pembayaran adalah email penumpang yang didaftarkan pertama kali.
      </div>

    <div style="text-align:right; margin-top:10px;">
      <button class="submit-btn" onclick="goToStep3()">Submit</button>
    </div>

  </div>
</div>


</div>
