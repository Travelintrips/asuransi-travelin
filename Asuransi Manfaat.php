<script>
function goToPayment(title, option) {
    const params = new URLSearchParams(window.location.search);
    const scanId = params.get("scan_id");

    // Build URL safely (URLSearchParams akan meng-handle encoding sekali saja)
    const url = new URL(window.location.origin + '/inflight-payment/');
    url.searchParams.set('scan_id', scanId || '');
    url.searchParams.set('option', option);
    url.searchParams.set('title', title);

    window.location.href = url.toString();
}
</script>
<style>
/* GENERAL */
body {
    font-family: font-family: Roboto, sans-serif;
    background: #fff;
    margin: 0;
    padding: 0;
}

/* TOP BAR */
.top-bar {
  display: flex;
  align-items: flex-start;
  padding: 12px 14px;
  border-bottom: 1px solid #e5e5e5;
  font-size: 42px;
  font-weight: 600;
  background: white;
  gap: 370px;
}

/* FRAME */
.container {
  width: 100%;
  max-width: 1200px;
  background: #00a1b3;
  border-radius: 18px;
  box-shadow: 0 0 10px rgba(0,0,0,0.15);
  overflow: hidden;
  border: 1px solid #e0e0e0;
}

.back-arrow {
  font-size: 48px;
  font-weight: 600;
  cursor: pointer;
  line-height: 1;
  padding-bottom: 12px;
  
}

.page-title {
    flex: ;
    text-align: center;
    margin-right: 20px;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}

/* CONTENT WRAPPER */
.content-wrapper {
    padding: 15px;
    max-width: 500px;
    margin: auto;
}

/* BENEFIT CARD */
.benefit-card {
    background: #fff;
    padding: 16px;
    border-radius: 6px;
    margin-bottom: 25px;
    box-shadow: 0 3px 6px rgba(0,0,0,0.1);
}

.benefit-header {
    display: flex;
    justify-content: space-between;
    font-size: 16px;
    margin-bottom: 10px;
}

.price {
    color: #d90000;
    font-weight: bold;
    font-size: 14px;
}

.benefit-title {
    font-weight: bold;
    font-size: 22px;
    margin-bottom: 6px;
    color: black;
    border-bottom: 3px solid #20a19c;
}

.benefit-desc {
    font-size: 0px;
    line-height: 20px;
    color: #444;
}
.benefit-item {
    margin-bottom: 10px;
    padding-bottom: 6px;
    
}

.benefit-title-dark {
    font-weight: bold;
    color: #222;       /* warna lebih gelap */
    font-size: 16px;
}

.benefit-small {
    font-size: 14px;
    color: #555;       /* abu gelap */
}

.pilih-btn {
    margin-top: 18px;
    background: #00a4b3;
    border: none;
    color: #fff;
    padding: 2px;
    width: 80px;
    border-radius: 4px;
    font-size: 15px;
    cursor: pointer;
    text-align: center;
    display: block;
    margin-left: auto;
    margin-right: auto;
}

.choose-btn:hover {
    background: #008f99;
}

/* RESPONSIVE */
@media (min-width: 768px) {
    .content-wrapper {
        max-width: 800px;
    }
    .benefit-card {
        max-width: 600px;
        margin-left: auto;
        margin-right: auto;
    }
}

/* RESPONSIVE */
@media (max-width: 768px) {
  .top-bar { font-size: 22px; padding: 10px; font-family:Roboto, sans-serif; gap: 30px; align-items: center; }
  .back-arrow { font-size: 54px; }
  .card { padding: 22px; }
  .benefit-header { display: flex;
    justify-content: space-between;
    font-size: 12px;
    margin-bottom: 10px;}
  .benefit-title {
    font-weight: bold;
    font-size: 17px;
    margin-bottom: 2px;
    color: black;
    border-bottom: 3px solid #20a19c;
    font-family: Roboto, sans-serif ;
   }
   .benefit-desc {
    font-size: 10px;
    line-height: 19px;
    color: #444;
}
.benefit-title-dark {
    font-weight: bold;
    color: #222;
    font-size: 15px;
}
.benefit-small {
    font-size: 14px;
    color: #555;     
}

}
</style>

<div class="container">

<div class="top-bar">
    <span class="back-arrow" onclick="history.back()">&#8592;</span>
    <span class="page-title">Pilih Manfaat</span>
</div>

<div class="content-wrapper">

    <!-- OPTION 1 -->
    <div class="benefit-card">
        <div class="benefit-header">
            <span><b>CHUBB</b> &nbsp; Chubb Syariah</span>
            <span class="price">Rp15.000/pax</span>
        </div>

        <div class="benefit-title">Asuransi Kecelakaan Diri Inflight (Opsi 1)</div>
        
        <div class="benefit-desc">

    <div class="benefit-item">
        <div class="benefit-title-dark">Santunan Kematian Karena Kecelakaan</div>
        <div class="benefit-small">Manfaat hingga Rp150.000.000</div>
    </div>

    <div class="benefit-item">
        <div class="benefit-title-dark">Cacat Tetap Karena Kecelakaan</div>
        <div class="benefit-small">Manfaat hingga Rp150.000.000</div>
    </div>

    <div class="benefit-item" style="border-bottom:none;">
        <div class="benefit-title-dark">Penggantian Biaya Pengobatan Karena Kecelakaan</div>
        <div class="benefit-small">Manfaat hingga Rp15.000.000</div>
    </div>

</div>

        <button class="pilih-btn" onclick="goToPayment('Asuransi Kecelakaan Diri Inflight (Opsi 1)', 1)">Pilih</button>
    </div>

    <!-- OPTION 2 -->
    <div class="benefit-card">
        <div class="benefit-header">
            <span><b>CHUBB</b> &nbsp; Chubb Syariah</span>
            <span class="price">Rp25.000/pax</span>
        </div>

        <div class="benefit-title">Asuransi Kecelakaan Diri Inflight (Opsi 2)</div>
        
        <div class="benefit-desc">

    <div class="benefit-item">
        <div class="benefit-title-dark">Santunan Kematian Karena Kecelakaan</div>
        <div class="benefit-small">Manfaat hingga Rp250.000.000</div>
    </div>

    <div class="benefit-item">
        <div class="benefit-title-dark">Cacat Tetap Karena Kecelakaan</div>
        <div class="benefit-small">Manfaat hingga Rp250.000.000</div>
    </div>

    <div class="benefit-item" style="border-bottom:none;">
        <div class="benefit-title-dark">Penggantian Biaya Pengobatan Karena Kecelakaan</div>
        <div class="benefit-small">Manfaat hingga Rp25.000.000</div>
    </div>

</div>
     
 <button class="pilih-btn" onclick="goToPayment('Asuransi Kecelakaan Diri Inflight (Opsi 2)', 2)">Pilih</button>
</div>
</div>
</div>