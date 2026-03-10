<?php
/**
 * Plugin Name: TravelIn Scan + Paylabs Checkout
 * Description: Integrasi Checkout Scan (Asuransi) dengan Paylabs H5 (Virtual Account, QRIS, eWallet) + REST API.
 * Version: 1.0.0
 * Author: TravelInTrips
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TravelIn_Paylabs_Scan {

    /**
     * TODO: GANTI DENGAN DATA PAYLABS PUNYA KAMU
     */
    const MERCHANT_ID = '010370'; // <- ganti kalau perlu

    // !!! SANGAT DISARANKAN simpan di wp-config atau env, tapi sementara pakai placeholder
    const PRIVATE_KEY =<<<KEY
-----BEGIN RSA PRIVATE KEY-----
-----END RSA PRIVATE KEY-----
KEY;

    // 'production' atau 'sandbox'
    const ENVIRONMENT = 'sandbox';

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
        $this->ensure_table_columns();
    }

    public function ensure_table_columns() {
        global $wpdb;
        $table = $wpdb->prefix . 'flight_scans';
        
        // Check and add route column if not exists
        $column_route = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'route'",
            DB_NAME, $table
        ) );
        if ( empty( $column_route ) ) {
            $wpdb->query( "ALTER TABLE $table ADD COLUMN route VARCHAR(255) DEFAULT NULL" );
        }
        
        // Check and add departure_code column
        $column_dep = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'departure_code'",
            DB_NAME, $table
        ) );
        if ( empty( $column_dep ) ) {
            $wpdb->query( "ALTER TABLE $table ADD COLUMN departure_code VARCHAR(10) DEFAULT NULL" );
        }
        
        // Check and add arrival_code column
        $column_arr = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'arrival_code'",
            DB_NAME, $table
        ) );
        if ( empty( $column_arr ) ) {
            $wpdb->query( "ALTER TABLE $table ADD COLUMN arrival_code VARCHAR(10) DEFAULT NULL" );
        }
    }

    public function register_routes() {
        // 1) Daftar metode pembayaran untuk Step 4 (frontend)
        register_rest_route( 'paylabs/v1', '/payment-methods', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_payment_methods' ],
            'permission_callback' => '__return_true',
        ] );

        // 2) Create order dari Step 4 (dipanggil JS /create-order)
        register_rest_route( 'scan/v1', '/create-order', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'create_order' ],
            'permission_callback' => '__return_true',
        ] );

        // 3) Callback/Notify dari Paylabs (opsional, untuk update status)
        register_rest_route( 'paylabs/v1', '/callback', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_callback' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /**
     * BASE URL PAYLABS
     */
    protected function get_paylabs_base_url() {
        if ( self::ENVIRONMENT === 'sandbox' ) {
            // kalau punya URL sandbox, taruh di sini
           // return 'https://pay.paylabs.co.id';
            return 'https://sit-pay.paylabs.co.id';
        }
   //    return 'https://pay.paylabs.co.id';
        return 'https://sit-pay.paylabs.co.id';
    }

    /**
     * ENDPOINT: GET /wp-json/paylabs/v1/payment-methods
     * Dipakai di JS: loadPaymentMethods()
     */
    public function get_payment_methods( $request ) {

        // Sementara kita buat statis. Kalau nanti mau dynamic dari Paylabs API, bisa diubah.
        $channels = [
            [ 'code' => 'BCA_VA',   'name' => 'BCA Virtual Account' ],
            [ 'code' => 'BNI_VA',   'name' => 'BNI Virtual Account' ],
            [ 'code' => 'BRI_VA',   'name' => 'BRI Virtual Account' ],
            [ 'code' => 'MANDIRI_VA','name' => 'Mandiri Virtual Account' ],
            //[ 'code' => 'QRIS',     'name' => 'QRIS' ],
            [ 'code' => 'OVO',      'name' => 'OVO' ],
            [ 'code' => 'DANA',     'name' => 'DANA' ],
        ];

        return [
            'success'  => true,
            'channels' => $channels,
        ];
    }

    /**
     * ENDPOINT: POST /wp-json/scan/v1/create-order
     * Dipanggil dari JS createOrder(scanId, optionName, price, pax, total)
     */
    public function create_order($request) {
        date_default_timezone_set('Asia/Jakarta');

        global $wpdb;

        // ================================
        // AMBIL DATA REQUEST
        // ================================
        $scan_id     = sanitize_text_field($request['scan_id'] ?? '');
        $option_name_raw = $request['option_name'] ?? '';
        $price       = floatval($request['price'] ?? 0);
        $pax_input   = intval($request['pax'] ?? 1);
        $total       = floatval($request['total'] ?? 0);
        $product_id  = intval($request['product_id'] ?? 0);

        // ================================
        // NORMALISASI NAMA OPSI
        // ================================
        $option_name = wp_unslash($option_name_raw);
        // Decode HTML entities yang mungkin ter-encode
        $option_name = html_entity_decode($option_name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Decode lagi jika masih ada entities
        $option_name = html_entity_decode($option_name, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // ================================
        // VALIDASI
        // ================================
        if (empty($scan_id) || empty($option_name)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Data tidak lengkap.',
            ], 400);
        }

        // ================================
        // AMBIL RUTE DARI PARENT SCAN
        // ================================
        $parent_scan = $wpdb->get_row(
            $wpdb->prepare("SELECT route, departure_code, arrival_code FROM {$wpdb->prefix}flight_scans WHERE id = %d AND type = 'scan'", $scan_id),
            ARRAY_A
        );
        $route = $parent_scan['route'] ?? '';
        $departure_code = $parent_scan['departure_code'] ?? '';
        $arrival_code = $parent_scan['arrival_code'] ?? '';
        
        // Format route dengan kode bandara
        $formatted_route = $route;
        if (!empty($route) && !empty($departure_code) && !empty($arrival_code)) {
            $cities = explode(' - ', $route);
            if (count($cities) == 2) {
                $formatted_route = trim($cities[0]) . ' (' . $departure_code . ') - ' . trim($cities[1]) . ' (' . $arrival_code . ')';
            }
        }
        
        error_log("DEBUG: scan_id=$scan_id, route=$route, formatted=$formatted_route");

        // ================================
        // AMBIL SEMUA PASSENGER
        // ================================
        $passengers = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}flight_scans WHERE scan_id = %d", $scan_id),
            ARRAY_A
        );

        if (!$passengers) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Data scan tidak ditemukan.',
            ], 404);
        }

        // daftar nama untuk tampil di order
        $passenger_names = array_column($passengers, 'nama_peserta');
        $passenger_list  = implode(", ", $passenger_names);

        // ambil passenger pertama untuk billing
        $first_p = $passengers[0];

        $customer_name  = $first_p['nama_peserta'] ?? "Customer";
        $customer_email = $first_p['email'] ?? "";
        $customer_phone = $first_p['telepon_peserta'] ?? "0811111111";

        // hitung pax
        $pax = count($passengers);

        // hitung total
        if (empty($total)) {
            $total = $price * $pax;
        }

        // ================================
        // BILLING DATA (AMBIL DARI PENUMPANG PERTAMA)
        // ================================
        $first = $passengers[0];

        $customer_name  = $first['nama_peserta'] ?? "Customer";
        $customer_phone = $first['telepon_peserta'] ?? "0811111111";
        $customer_email = $first['email'] ?? "";

        // ================================
        // PRODUCT NAME
        // ================================
        $product_name = $option_name;

        // ================================
        // WOOCOMMERCE ORDER
        // ================================
        if (!function_exists('wc_create_order')) {
            return ["success" => false, "message" => "WooCommerce tidak aktif."];
        }

        $order = wc_create_order();

        $product = wc_get_product($product_id);
        if (!$product) {
            return ["success" => false, "message" => "Produk WooCommerce tidak ditemukan."];
        }

        // Tambahkan product sesuai pax
        $order->add_product($product, count($passengers));

        // Billing Info
        $order->set_billing_first_name($customer_name);
        $order->set_billing_phone($customer_phone);
        $order->set_billing_email($customer_email);

        // Hitung
        $order->calculate_totals();
        $order->update_status('pending');

        $order_id = $order->get_id();

        // ================================
        // SIMPAN META
        // ================================
        $invoice = "INV-" . time() . "-" . rand(100,999);

        update_post_meta($order_id, '_flight_passengers', $passenger_list);
        
        // ================================
        // SIMPAN DETAIL PESERTA KE ORDER
        // ================================
        // Simpan data lengkap peserta untuk ditampilkan di order
        $passenger_details = [];
        foreach ($passengers as $p) {
            $passenger_details[] = [
                'nama' => $p['nama_peserta'] ?? '',
                'kode_penerbangan' => $p['kode_penerbangan'] ?? '',
                'tanggal_penerbangan' => $p['tanggal_penerbangan'] ?? '',
                'route' => $formatted_route,
                'scan_id' => $scan_id
            ];
        }
        update_post_meta($order_id, '_flight_passenger_details', $passenger_details);
        
        // Tambahkan catatan order dengan daftar peserta
        $passenger_note = "Daftar Peserta Asuransi:\n";
        foreach ($passengers as $idx => $p) {
            $no = $idx + 1;
            $nama = $p['nama_peserta'] ?? '-';
            $flight = $p['kode_penerbangan'] ?? '-';
            $date = $p['tanggal_penerbangan'] ?? '-';
            $phone = $p['telepon_peserta'] ?? '-';
            $email = $p['email'] ?? '-';
            $passenger_note .= "{$no}. {$nama} - {$flight} - {$date}\n";
        }
        $order->add_order_note($passenger_note);
        update_post_meta($order_id, '_flight_invoice', $invoice);
        update_post_meta($order_id, '_flight_total', $total);
        update_post_meta($order_id, '_flight_pax', count($passengers));
        update_post_meta($order_id, '_is_insurance', 'yes');  // TANDAI SEBAGAI ORDER ASURANSI
        // Simpan nama produk/opsi untuk dipakai di notifikasi/sertifikat
        update_post_meta($order_id, '_flight_product_name', $product_name);

        // ================================
        // SIMPAN KE wp_flight_orders
        // ================================
        $table_orders = $wpdb->prefix . "flight_orders";

        $wpdb->insert($table_orders, [
            "scan_id"        => $scan_id,
            "option_name"    => $option_name,
            "price"          => $price,
            "pax"            => count($passengers),
            "total"          => $total,
            "invoice"        => $invoice,
            "payment_method" => "", // tidak dipakai lagi
            "status"         => "PENDING",
            "payment_token"  => "",
            "passengers"     => $passenger_list,
            "created_at"     => current_time('mysql')
        ]);

        $custom_order_id = $wpdb->insert_id;

        // ================================
        // PAYLABS H5 — CREATE LINK
        // ================================
        $merchantId  = self::MERCHANT_ID;
        $private_key = self::PRIVATE_KEY;

        // ID unik untuk request & trade no
        $idRequest       = (string) wp_rand(100000, 999999);
        $merchantTradeNo = "WC-" . $order_id;

        // PATH & URL
        $path      = "/payment/v2.3/h5/createLink";
        $base_url  = $this->get_paylabs_base_url();
        $url       = $base_url . $path;

        // TIMESTAMP
        $date = date("Y-m-d\TH:i:s.vP");

        // AMOUNT dalam 2 desimal
        $amountStr = number_format($total, 2, '.', '');

        // BODY
        $bodyArr = [
            "requestId"       => $idRequest,
            "merchantId"      => $merchantId,
            "merchantTradeNo" => $merchantTradeNo,
            "amount"          => $amountStr,
            "payer"           => $customer_name,
            "phoneNumber"     => $customer_phone,
            "productName"     => $product_name,
            "redirectUrl" => "https://travelintrips.co.id/",
            //"redirectUrl"     => site_url("/payment-success?inv=$invoice"),
            "notifyUrl"       => site_url("/wp-json/paylabs/v1/callback"),
        ];

        // JSON
        $jsonBody = json_encode($bodyArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // SHA256
        $shaJson  = strtolower(hash('sha256', $jsonBody));

        // STRING TO SIGN
        $signatureBefore = "POST:" . $path . ":" . $shaJson . ":" . $date;

        // SIGNATURE
        $binary_signature = '';
        $signOk = openssl_sign($signatureBefore, $binary_signature, $private_key, OPENSSL_ALGO_SHA256);

        if (!$signOk) {
            return [
                "success"      => false,
                "message"      => "Gagal membuat signature",
                "stringToSign" => $signatureBefore,
                "jsonBody"     => $jsonBody
            ];
        }

        $signature = base64_encode($binary_signature);

        // HEADERS
        $headers = [
            "X-TIMESTAMP: $date",
            "X-SIGNATURE: $signature",
            "X-PARTNER-ID: $merchantId",
            "X-REQUEST-ID: $idRequest",
            "Content-Type: application/json;charset=utf-8",
        ];

        // CURL
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $errMsg = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0 || $raw === false) {
            return [
                "success" => false,
                "message" => "Curl error: " . $errMsg,
                "errno"   => $errno,
                "url"     => $url,
                "body"    => $bodyArr,
            ];
        }

        // DECODE
        $result = json_decode($raw, true);

        if (!isset($result["errCode"]) || $result["errCode"] !== '0') {
            return [
                "success" => false,
                "message" => "Response Paylabs tidak valid",
                "raw"     => $raw,
                "parsed"  => $result,
            ];
        }

        // URL bisa di root level atau di data.url
        $payment_url = $result["url"] ?? $result["data"]["url"] ?? null;
        
        if (empty($payment_url)) {
            return [
                "success" => false,
                "message" => "URL pembayaran tidak ditemukan",
                "raw"     => $raw,
                "parsed"  => $result,
            ];
        }

        // SIMPAN PAYMENT URL KE TABLE CUSTOM
        $wpdb->update(
            $table_orders,
            [ "payment_url" => sanitize_text_field($payment_url) ],
            [ "id" => $custom_order_id ]
        );

        // RETURN KE FRONTEND
        return [
            "success"     => true,
            "payment_url" => $payment_url,
            "invoice"     => $invoice,
            "order_id"    => $order_id,
        ];
    }

    /**
     * ENDPOINT: POST /wp-json/paylabs/v1/callback
     * Notify dari Paylabs (status pembayaran)
     * Nanti bisa kamu parse & update status order di DB.
     */
    public function handle_callback( $request ) {

        // RAW JSON dari Paylabs
        $body = $request->get_body();
        $payload = json_decode($body, true);

        if (!$payload) {
            error_log("Paylabs callback ERROR: invalid JSON");
            return [ "success" => false ];
        }

        // Decode HTML entitas
        foreach (['productName', 'goodsInfo'] as $key) {
            if (!empty($payload[$key])) {
                $payload[$key] = html_entity_decode($payload[$key], ENT_QUOTES, 'UTF-8');
            }
        }

        error_log("PAYLABS CALLBACK CLEAN: " . print_r($payload, true));

        // ---------------------------------------------
        // 1️⃣ Ambil ORDER ID dari merchantTradeNo (bukan orderNo!)
        // ---------------------------------------------
        $merchantTradeNo = $payload["merchantTradeNo"] ?? "";
        $order_no = str_replace("WC-", "", $merchantTradeNo);

        if (!$order_no) {
            error_log("Paylabs callback: merchantTradeNo missing");
            return [ "success" => false ];
        }

        $order = wc_get_order($order_no);

        if (!$order) {
            error_log("Paylabs callback: order not found ($order_no)");
            return [ "success" => false ];
        }

        // ---------------------------------------------
        // 2️⃣ Ambil metode pembayaran dari Paylabs
        // ---------------------------------------------
        $paymentType  = $payload["paymentType"] ?? "Paylabs";
        $method_slug  = strtolower($paymentType);
        $method_title = $paymentType;

        // Simpan metode pembayaran
        $order->set_payment_method($method_slug);
        $order->set_payment_method_title($method_title);

        update_post_meta($order_no, '_payment_method', $method_slug);
        update_post_meta($order_no, '_payment_method_title', $method_title);

        // ---------------------------------------------
        // 3️⃣ Tandai order sebagai selesai dibayar
        // ---------------------------------------------
        $order->payment_complete();
        
        $order->update_status('completed');

        // ---------------------------------------------
        // 4️⃣ Update status di wp_flight_orders
        // ---------------------------------------------
        $table_orders = $wpdb->prefix . "flight_orders";
        $invoice = get_post_meta($order_no, '_flight_invoice', true);
        
        if ($invoice) {
            $wpdb->update($table_orders, [
                'status' => 'PAID',
                'payment_method' => $method_title
            ], ['invoice' => $invoice]);
            error_log("PAYLABS: Updated wp_flight_orders - Invoice: " . $invoice . ", Status: PAID, Payment Method: " . $method_title);
        }

        // Catatan order
        $order->add_order_note("Pembayaran berhasil via Paylabs — {$paymentType}");

        return [
            "success" => true,
            "paymentMethod" => $paymentType
        ];
    }

}

new TravelIn_Paylabs_Scan();

// ===============================
// 🔥 ADD CALLBACK API HERE
// ===============================

//1
add_action('rest_api_init', function() {
    register_rest_route('paylabs/v1', '/callback', [
        'methods'  => 'POST',
        'callback' => 'travelin_paylabs_callback_handler',
        'permission_callback' => '__return_true'
    ]);
});


//2
function travelin_paylabs_callback_handler(WP_REST_Request $request) {
    global $wpdb;

    // === Ambil Header ===
    $httpSign  = $_SERVER['HTTP_X_SIGNATURE']  ?? '';
    $dateTime  = $_SERVER['HTTP_X_TIMESTAMP']  ?? '';
    $rawdata   = file_get_contents('php://input');

    // Log untuk debugging
    error_log("PAYLABS CALLBACK RAW: " . $rawdata);

    // === Ambil Public Key dari PAYLABS ===
    $publicKey = <<<KEY
-----BEGIN PUBLIC KEY-----
-----END PUBLIC KEY-----
KEY;

    // === Parse data dulu untuk proses ===
    $data = json_decode($rawdata, true);
    
    if (!$data) {
        error_log("PAYLABS CALLBACK: Invalid JSON");
        return ["success" => false, "message" => "Invalid JSON"];
    }

    error_log("PAYLABS CALLBACK PARSED: " . print_r($data, true));

    // === Proses pembayaran berdasarkan status ===
    if (isset($data['errCode']) && $data['errCode'] == '0' && isset($data['status']) && $data['status'] == '02') {

        // Payment sukses
        $merchantTradeNo = $data['merchantTradeNo'] ?? '';
        $paymentType = $data['paymentType'] ?? 'Virtual Account';
        error_log("PAYLABS: Payment SUCCESS for " . $merchantTradeNo . " via " . $paymentType);
        
        // Update order status dengan payment type
        travelin_update_order_paid($merchantTradeNo, $paymentType);
        
        // Update WooCommerce order juga
        $order_id = str_replace("WC-", "", $merchantTradeNo);
        $order = wc_get_order($order_id);
        if ($order) {
            $paymentType = $data['paymentType'] ?? $order->get_payment_method_title() ?? 'Virtual Account';
            $order->set_payment_method(strtolower($paymentType));
            $order->set_payment_method_title($paymentType);
            $order->payment_complete();
            $order->update_status('completed');
            $order->add_order_note("Pembayaran berhasil via Paylabs — {$paymentType}");
            
            // Update wp_flight_orders
            $table_orders = $wpdb->prefix . "flight_orders";
            $invoice = get_post_meta($order_id, '_flight_invoice', true);
            if ($invoice) {
                $wpdb->update($table_orders, [
                    'status' => 'PAID',
                    'payment_method' => $paymentType
                ], ['invoice' => $invoice]);
                error_log("PAYLABS CALLBACK: Updated wp_flight_orders - Invoice: " . $invoice . ", Status: PAID, Payment Method: " . $paymentType);
            }
        }

    } elseif (isset($data['errCode']) && $data['errCode'] == '0' && isset($data['status']) && $data['status'] == '09') {

        // Payment gagal
        $merchantTradeNo = $data['merchantTradeNo'] ?? '';
        error_log("PAYLABS: Payment FAILED for " . $merchantTradeNo);
        travelin_update_order_failed($merchantTradeNo);
    }

    // === Buat response ke Paylabs ===
    $responseBody = [
        "merchantId" => $data['merchantId'] ?? '',
        "requestId"  => $data['requestId'] ?? '',
        "errCode"    => "0"
    ];

    // SIGN response
    $privateKey = <<<KEY
-----BEGIN RSA PRIVATE KEY-----
-----END RSA PRIVATE KEY-----
KEY;

    $signature = travelin_generate_hash(json_encode($responseBody), $privateKey, "/", $dateTime);

    // Header respon ke Paylabs
    header("Content-Type: application/json;charset=utf-8");
    header("X-TIMESTAMP: ".$dateTime);
    header("X-SIGNATURE: ".$signature);
    header("X-PARTNER-ID: ".$data['merchantId']);
    header("X-REQUEST-ID: ".$data['requestId']);

    return $responseBody;
}


function travelin_verify_sign($dataToSign, $sign, $dateTime, $publicKey)
{
    $binary_signature = base64_decode($sign);
    $shaJson = strtolower(hash('sha256', $dataToSign));

    // Path harus sesuai dengan endpoint callback yang didaftarkan
    $signatureAfter = "POST:/wp-json/paylabs/v1/callback:" . $shaJson . ":" . $dateTime;

    $pubKey = openssl_pkey_get_public($publicKey);
    if (!$pubKey) return false;

    $verify = openssl_verify($signatureAfter, $binary_signature, $pubKey, OPENSSL_ALGO_SHA256);

    return $verify === 1;
}

function travelin_generate_hash($jsonBody, $private_key, $endpoint, $orderTime)
{
    $shaJson = strtolower(hash('sha256', $jsonBody));
    $signatureBefore = "POST:" . $endpoint . ":" . $shaJson . ":" . $orderTime;

    openssl_sign($signatureBefore, $binary, $private_key, OPENSSL_ALGO_SHA256);

    return base64_encode($binary);
}

function travelin_update_order_paid($tradeNo, $paymentType = 'Virtual Account') {
    global $wpdb;
    
    // Extract order_id from merchantTradeNo (format: WC-{order_id})
    $order_id = str_replace("WC-", "", $tradeNo);
    
    // Jika paymentType kosong, ambil dari WooCommerce order
    if (empty($paymentType) || $paymentType === 'Virtual Account') {
        $order = wc_get_order($order_id);
        if ($order) {
            $paymentType = $order->get_payment_method_title() ?: 'Virtual Account';
        }
    }
    
    // Update wp_flight_orders berdasarkan invoice yang terkait dengan order_id
    $table_orders = $wpdb->prefix . "flight_orders";
    
    // Cari invoice dari WooCommerce order meta
    $invoice = get_post_meta($order_id, '_flight_invoice', true);
    
    if ($invoice) {
        $wpdb->update($table_orders, [
            'status' => 'PAID',
            'payment_method' => $paymentType
        ], ['invoice' => $invoice]);
        error_log("PAYLABS: Updated wp_flight_orders - Invoice: " . $invoice . ", Status: PAID, Payment Method: " . $paymentType);
    }
    
    // Juga update berdasarkan scan_id jika ada
    // Fallback: update semua order dengan status PENDING yang terkait
    $wpdb->query($wpdb->prepare(
        "UPDATE {$table_orders} SET status = 'PAID', payment_method = %s WHERE status = 'PENDING' AND invoice = %s",
        $paymentType,
        $invoice
    ));
}

function travelin_update_order_failed($tradeNo) {
    global $wpdb;
    
    // Extract order_id from merchantTradeNo (format: WC-{order_id})
    $order_id = str_replace("WC-", "", $tradeNo);
    
    // Update wp_flight_orders
    $table_orders = $wpdb->prefix . "flight_orders";
    
    // Cari invoice dari WooCommerce order meta
    $invoice = get_post_meta($order_id, '_flight_invoice', true);
    
    if ($invoice) {
        $wpdb->update($table_orders, [
            'status' => 'FAILED'
        ], ['invoice' => $invoice]);
    }
}


// ===============================================
// 🔥 2. FIX WOOCOMMERCE STATUS JADI "COMPLETED"
// ===============================================
add_filter('woocommerce_payment_complete_order_status', function($status, $order_id, $order){
    return 'completed';   // PAKSA Completed
}, 10, 3);

// ===============================================
// 🔥 2.1 GABUNG: UPDATE wp_flight_orders & KIRIM SERTIFIKAT SAAT ORDER COMPLETED
// ===============================================
add_action('woocommerce_order_status_completed', function($order_id, $order) {
    global $wpdb;
    
    // 1️⃣ UPDATE wp_flight_orders
    // Ambil invoice dari order meta
    $invoice = get_post_meta($order_id, '_flight_invoice', true);
    
    if ($invoice) {
        $table_orders = $wpdb->prefix . "flight_orders";
        
        // Ambil payment method dari order
        $payment_method = $order->get_payment_method_title();
        if (empty($payment_method)) {
            $payment_method = 'Virtual Account';
        }
        
        // Update status dan payment_method
        $updated = $wpdb->update(
            $table_orders, 
            [
                'status' => 'PAID',
                'payment_method' => $payment_method
            ], 
            ['invoice' => $invoice]
        );
        
        error_log("WOOCOMMERCE COMPLETED HOOK: Order #{$order_id}, Invoice: {$invoice}, Payment Method: {$payment_method}, Rows Updated: {$updated}");
    }
    
    // 2️⃣ KIRIM SERTIFIKAT VIA WHATSAPP JIKA ORDER ASURANSI
    // sebelumnya kami menggunakan meta _is_insurance, namun beberapa order mungkin dibuat
    // langsung lewat admin tanpa meta tersebut. cek juga keberadaan _flight_invoice sebagai
    // indikator order seharusnya menggunakan asuransi.
    $is_insurance = get_post_meta($order_id, '_is_insurance', true);
    $has_invoice  = get_post_meta($order_id, '_flight_invoice', true);
    if ($is_insurance === 'yes' || !empty($has_invoice)) {
        error_log("TRAVELIN: triggering WA send for order {$order_id} (is_insurance={$is_insurance}, invoice=" . esc_html($has_invoice) . ")");
        travelin_kirim_sertifikat_asuransi($order_id);
    }
}, 10, 2);


// ===============================================
// 🔥 3. TAMPILKAN DAFTAR PESERTA DI ORDER ADMIN
// ===============================================
add_action('woocommerce_admin_order_data_after_order_details', function($order) {
    $order_id = $order->get_id();
    $passenger_details = get_post_meta($order_id, '_flight_passenger_details', true);
    $passenger_list = get_post_meta($order_id, '_flight_passengers', true);
    
    if (!empty($passenger_details) || !empty($passenger_list)) {
        echo '<div class="order_data_column" style="width: 100%; margin-top: 20px; padding: 15px; background: #f8f8f8; border: 1px solid #ddd;">';
        echo '<h3 style="margin-top: 0;">Daftar Peserta Asuransi</h3>';
        
        if (!empty($passenger_details) && is_array($passenger_details)) {
            echo '<table style="width: 100%; border-collapse: collapse;">';
            echo '<thead><tr style="background: #e0e0e0;">';
            echo '<th style="padding: 8px; border: 1px solid #ccc; text-align: left;">No</th>';
            echo '<th style="padding: 8px; border: 1px solid #ccc; text-align: left;">Nama Peserta</th>';
            echo '<th style="padding: 8px; border: 1px solid #ccc; text-align: left;">Kode. Penerbangan</th>';
            echo '<th style="padding: 8px; border: 1px solid #ccc; text-align: left;">Route</th>';
            echo '<th style="padding: 8px; border: 1px solid #ccc; text-align: left;">Tanggal Penerbangan</th>';
            echo '</tr></thead><tbody>';
            
            foreach ($passenger_details as $idx => $p) {
                $no = $idx + 1;
                $nama = esc_html($p['nama'] ?? '-');
                $flight = esc_html($p['kode_penerbangan'] ?? '-');
                $route = esc_html($p['route'] ?? '-');
                $date = esc_html($p['tanggal_penerbangan'] ?? '-');
                
                echo "<tr>";
                echo "<td style='padding: 8px; border: 1px solid #ccc;'>{$no}</td>";
                echo "<td style='padding: 8px; border: 1px solid #ccc;'><strong>{$nama}</strong></td>";
                echo "<td style='padding: 8px; border: 1px solid #ccc;'>{$flight}</td>";
                echo "<td style='padding: 8px; border: 1px solid #ccc;'>{$route}</td>";
                echo "<td style='padding: 8px; border: 1px solid #ccc;'>{$date}</td>";
                echo "</tr>";
            }
            
            echo '</tbody></table>';
        } elseif (!empty($passenger_list)) {
            echo '<p>' . esc_html($passenger_list) . '</p>';
        }
        
        echo '</div>';
    }
});

// Tampilkan juga di email order
add_action('woocommerce_email_after_order_table', function($order, $sent_to_admin, $plain_text, $email) {
    $order_id = $order->get_id();
    $passenger_details = get_post_meta($order_id, '_flight_passenger_details', true);
    
    if (!empty($passenger_details) && is_array($passenger_details)) {
        if ($plain_text) {
            echo "\n\nDaftar Peserta Asuransi:\n";
            foreach ($passenger_details as $idx => $p) {
                $no = $idx + 1;
                echo "{$no}. {$p['nama']} - {$p['kode_penerbangan']} - {$p['tanggal_penerbangan']}\n";
            }
        } else {
            echo '<h2>Daftar Peserta Asuransi</h2>';
            echo '<table cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #e5e5e5;" border="1">';
            echo '<thead><tr>';
            echo '<th>No</th><th>Nama</th><th>Kode. Penerbangan</th><th>Tanggal Penerbangan</th>';
            echo '</tr></thead><tbody>';
            
            foreach ($passenger_details as $idx => $p) {
                $no = $idx + 1;
                echo "<tr>";
                echo "<td>{$no}</td>";
                echo "<td><strong>{$p['nama']}</strong></td>";
                echo "<td>{$p['kode_penerbangan']}</td>";
                echo "<td>{$p['tanggal_penerbangan']}</td>";
                echo "</tr>";
            }
            
            echo '</tbody></table>';
        }
    }
}, 10, 4);

// Tampilkan di halaman order details (frontend)
add_action('woocommerce_order_details_after_order_table', function($order) {
    $order_id = $order->get_id();
    $passenger_details = get_post_meta($order_id, '_flight_passenger_details', true);
    
    if (!empty($passenger_details) && is_array($passenger_details)) {
        echo '<h2>Daftar Peserta Asuransi</h2>';
        echo '<table class="woocommerce-table shop_table" style="width: 100%;">';
        echo '<thead><tr>';
        echo '<th>No</th><th>Nama</th><th>Kode Penerbangan</th><th>Route</th><th>Tanggal</th>';
        echo '</tr></thead><tbody>';
        
        foreach ($passenger_details as $idx => $p) {
            $no = $idx + 1;
            echo "<tr>";
            echo "<td>{$no}</td>";
            echo "<td><strong>" . esc_html($p['nama']) . "</strong></td>";
            echo "<td>" . esc_html($p['kode_penerbangan']) . "</td>";
            echo "<td>" . esc_html($p['route']) . "</td>";
            echo "<td>" . esc_html($p['tanggal_penerbangan']) . "</td>";
            echo "</tr>";
        }
        
        echo '</tbody></table>';
    }
});


// ======================================================
// 🔥 KIRIM FILE SERTIFIKAT ASURANSI VIA WHATSAPP (FONNTE)
// ======================================================
function travelin_kirim_sertifikat_asuransi($order_id){

    error_log("TRAVELIN: travelin_kirim_sertifikat_asuransi() called for order {$order_id}");

    $order = wc_get_order($order_id);

    if (!$order) {
        error_log("TRAVELIN: order {$order_id} not found");
        return;
    }

    // CEK FONNTE_TOKEN SUDAH DIDEFINISIKAN
    if (!defined('FONNTE_TOKEN') || empty(FONNTE_TOKEN)) {
        error_log('SERTIFIKAT ASURANSI: FONNTE_TOKEN tidak didefinisikan. Definisikan di wp-config.php dengan: define("FONNTE_TOKEN", "token_anda_dari_fonnte");');
        return;
    }

    // Ambil data
    $nama      = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
    $phone     = $order->get_billing_phone();

    // Ambil nama produk jika disimpan saat create_order, fallback ke item order
    $produk = get_post_meta($order_id, '_flight_product_name', true);
    if (empty($produk)) {
        foreach ($order->get_items() as $item) {
            $produk = $item->get_name();
            break;
        }
    }
    if (empty($produk)) {
        $produk = 'Asuransi Perjalanan';
    }

    // Ambil detail peserta yang disimpan (ambil peserta pertama untuk kode/tanggal)
    $kode_flight = '';
    $tgl_flight = '';
    $passenger_details = get_post_meta($order_id, '_flight_passenger_details', true);
    if (!empty($passenger_details) && is_array($passenger_details)) {
        $firstP = $passenger_details[0];
        $kode_flight = trim($firstP['kode_penerbangan'] ?? '');
        $tgl_flight  = trim($firstP['tanggal_penerbangan'] ?? '');
    }

    // Normalisasi nomor 08 → 628
    if (substr($phone, 0, 1) == '0') {
        $phone = '62' . substr($phone, 1);
    }

    $file_url = content_url('/uploads/extra-files/sertifikat-' . $order_id . '.pdf');

    // Format pesan
    $caption =
"Peserta Asuransi

Produk: $produk
Nama Peserta: $nama
Nomor Telp: $phone
Kode Penerbangan: $kode_flight
Tanggal Penerbangan: $tgl_flight

Berikut terlampir sertifikat asuransi Anda.";

    // log payload for debugging
    error_log("TRAVELIN: sending WA, phone={$phone}, url={$file_url}, caption={$caption}");

    $payload = [
        'target'  => $phone,
        'url'     => $file_url,
        // Fonnte API expects a `message` field (error shows "message cannot empty").
        // caption may be ignored or optional.
        'message' => $caption,
        'caption' => $caption,
    ];

    $response = wp_remote_post('https://api.fonnte.com/send', [
        'headers' => [
            'Authorization' => FONNTE_TOKEN
        ],
        'body' => $payload,
        'timeout' => 30
    ]);

    if (is_wp_error($response)) {
        error_log('SERTIFIKAT ASURANSI - WA ERROR: ' . $response->get_error_message());
    } else {
        $body = wp_remote_retrieve_body($response);
        error_log('SERTIFIKAT ASURANSI - WA SENT untuk Order #' . $order_id . ': ' . $body);
    }
}

// HOOK INI SUDAH DIGABUNG KE DALAM HOOK UTAMA woocommerce_order_status_completed DI ATAS
// (Lihat bagian 2.1 untuk implementasi lengkapnya)


// Tambahkan daftar peserta ke PDF invoice WPO WCPDF
add_action('wpo_wcpdf_after_order_details', 'pdf_invoice_daftar_peserta_fullwidth', 10, 2);

function pdf_invoice_daftar_peserta_fullwidth($template_type, $order) {

    if ($template_type !== 'invoice') {
        return;
    }

    if (get_post_meta($order->get_id(), '_is_insurance', true) !== 'yes') {
        return;
    }

    $details = get_post_meta($order->get_id(), '_flight_passenger_details', true);

    if (empty($details)) {
        return;
    }

    echo '<div style="margin-top:30px; width:100%;">';
    echo '<h3 style="margin-bottom:10px;">Daftar Peserta Asuransi</h3>';

    echo '<table style="width:100%; border-collapse: collapse;" border="1" cellpadding="8">';
    echo '<tr style="background:#f2f2f2;">
            <th align="left">Nama</th>
            <th align="left">Kode Penerbangan</th>
            <th align="left">Tanggal Penerbangan</th>
          </tr>';

    foreach ($details as $p) {
        echo '<tr>
                <td>' . esc_html($p['nama']) . '</td>
                <td>' . esc_html($p['kode_penerbangan']) . '</td>
                <td>' . esc_html($p['tanggal_penerbangan']) . '</td>
              </tr>';
    }

    echo '</table>';
    echo '</div>';
}

add_action('wp_ajax_save_flight_scan','save_flight_scan');
add_action('wp_ajax_nopriv_save_flight_scan','save_flight_scan');

function save_flight_scan(){

    global $wpdb;

    $table = $wpdb->prefix . 'flight_scans';

    $booking_code = sanitize_text_field($_POST['booking_code']);
    $route = sanitize_text_field($_POST['route']);

    $wpdb->insert(
        $table,
        [
            'booking_code' => $booking_code,
            'route' => $route,
            'scan_time' => current_time('mysql')
        ],
        [
            '%s',
            '%s',
            '%s'
        ]
    );

    wp_die();
}


//pdf SERTIFIKAT
function generate_sertifikat_pdf($order, $passenger) {

    error_log("GENERATE PDF: passenger data: " . print_r($passenger, true));

    $upload_dir = wp_upload_dir();
    $folder = $upload_dir['basedir'] . '/certificates/';

    if (!file_exists($folder)) {
        wp_mkdir_p($folder);
    }

    $invoice = $order->get_meta('_flight_invoice');
    $certificate_number = '000-' . $order->get_id() . date('Y');
    $filename  = 'Sertifikat-' . sanitize_file_name($passenger['nama']) . '-' . $order->get_id() . '.pdf';
    $file_path = $folder . $filename;

    if (file_exists($file_path)) {
        return $file_path;
    }

    require_once plugin_dir_path(__FILE__) . 'tcpdf/tcpdf.php';

    $pdf = new TCPDF();
    $pdf->SetMargins(0, 0, 0);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 11);

    $flight_date = date_i18n('F Y', strtotime($passenger['tanggal_penerbangan']));
$flight_code = esc_html($passenger['kode_penerbangan']);

$destination = esc_html($passenger['route'] ?? '-');

if ($destination === '-') {
    // Fallback: query from scan table
    global $wpdb;
    $scan_data = $wpdb->get_row(
        $wpdb->prepare("SELECT route, departure_code, arrival_code FROM {$wpdb->prefix}flight_scans WHERE id = %d AND type = 'scan'", $passenger['scan_id'] ?? 0),
        ARRAY_A
    );
    if ($scan_data) {
        $route = $scan_data['route'] ?? '';
        $dep = $scan_data['departure_code'] ?? '';
        $arr = $scan_data['arrival_code'] ?? '';
        if (!empty($route) && !empty($dep) && !empty($arr)) {
            $cities = explode(' - ', $route);
            if (count($cities) == 2) {
                $destination = trim($cities[0]) . ' (' . $dep . ') - ' . trim($cities[1]) . ' (' . $arr . ')';
            } else {
                $destination = $route;
            }
        } else {
            $destination = $route ?: '-';
        }
    }
}

$price = wc_price($order->get_total());

$price = wc_price($order->get_total());

    $html = '
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="height:100%; border-collapse:collapse; min-height:100%;">
        <tr>
            <td width="22%" style="background-color:#00a1b3; padding:0; vertical-align:top;">
                <table width="100%" height="100%" cellpadding="0" cellspacing="0" border="0" style="height:100%; min-height:100%;">
                    <tr><td style="background-color:#00a1b3; height:52px; padding:0;">&nbsp;</td></tr>
                    <tr style="height:100%;"><td style="background-color:#00a1b3; padding:0 14px 20px 14px; vertical-align:bottom;">
                        <span style="font-size:16px; font-weight:bold; color:#ffffff; letter-spacing:0.15em;">CHUBB<sup style="font-size:10px;">&#174;</sup></span>
                    </td></tr>
                </table>
            </td>
            <td width="78%" style="background-color:#00a1b3; padding:0; vertical-align:top;">

                <div style="background-color:#00a1b3; color:#ffffff; padding:14px 20px 10px 20px; margin:0;">
                    <span style="font-size:17px; font-weight:bold; letter-spacing:0.04em;">SERTIFIKAT ASURANSI</span><br/>
                    <span style="font-size:14px; font-style:italic; font-weight:bold; letter-spacing:0.04em;">INSURANCE CERTIFICATE</span>
                </div>

                <div style="background-color:#ffffff; padding:14px 20px 0px 20px; margin:0;">

                    <p style="font-size:11px; font-weight:bold; margin-bottom:12px;">
                        CHUBB SYARIAH PERSONAL ACCIDENT INFLIGHT
                    </p>

                    <p style="font-size:13px; font-weight:bold; margin-bottom:10px;">
                        Nama/<u>Name</u> :&nbsp;&nbsp;' . esc_html($passenger['nama']) . '
                    </p>

                    <p style="font-size:7px; margin-bottom:12px; line-height:1.6;">
                        PT Chubb Syariah Insurance Indonesia (selanjutnya disebut &#x201C; Perusahaan&#x201D;) selaku Operator selaku pengelola, dengan ini
                        memberikan manfaat asuransi&nbsp; kepada Peserta yang&nbsp; Namanya&nbsp; tersebut
                        dalam sertifikat asuransi,&nbsp; sesuai syarat -syarat,
                        kondisi-kondisi serta pengecualian sebagaimana <b>Polis Induk Chubb Syariah Personal Accident Inflight No (' . esc_html($invoice) . ').</b>
                    </p>

                    <table border="1" cellpadding="7" cellspacing="0" width="100%" style="border-collapse:collapse; font-size:9px; margin-bottom:12px;">
                        <tr>
                            <td width="46%" style="font-weight:bold;">Nomor Sertifikat</td>
                            <td width="54%">' . $certificate_number . '</td>
                        </tr>
                        <tr>
                            <td style="font-weight:bold;">Total Kontribusi yang dibayar</td>
                            <td>' . strip_tags($price) . '</td>
                        </tr>
                        <tr>
                            <td style="font-weight:bold;">Periode Perjalanan/Kode<br/>Penerbangan/Code Flight</td>
                            <td>' . $flight_date . ' / ' . $flight_code . '</td>
                        </tr>
                        <tr>
                            <td style="font-weight:bold;">Rencana perjalanan Yang dipilih</td>
                            <td></td>
                        </tr>
                        <tr>
                            <td style="font-weight:bold;">Tujuan/<u>Destination</u></td>
                            <td>' . $destination . '</td>
                        </tr>
                    </table>

                    <p style="font-size:8px; margin-bottom:6px;">
                        Customer <u>Service&nbsp; Hotline No :</u> 9999-9999 (24 Jam / 7 hari) <u>Email :</u>&nbsp; <u>..@Chubb.com</u>
                        &nbsp;&nbsp;<span style="border:1px solid #000; padding:1px 5px;">&nbsp;&nbsp;&nbsp;</span>
                    </p>

                    <p style="font-size:8px; margin-bottom:14px;">
                        Emergency Contact/<u>Kontak Darurat</u>____:&nbsp;&nbsp;&nbsp;&nbsp;..........
                    </p>

                    <p style="font-size:7px; color:#000000; margin-bottom:18px; line-height:1.6; font-weight:bold;">
                        Demikianlah Perusahaan telah menandatangani Polis Ini yang berlaku selama&nbsp; Periode Pertanggungan sebagaimana
                        ternyata dalam sertifikat asuransi namun dengan ketentuan bahwa polis ini tidak akan&nbsp;mengikat bagi perusahaan
                        kecuali telah ditandatangani&nbsp;oleh pejabat&nbsp;yang berwenang dari perusahaa
                    </p>

                    <p style="font-size:10px; margin-bottom:16px;">Name</p>

                    <p style="font-size:9px; margin-bottom:2px;">________________________________</p>
                    <p style="font-size:9px; font-weight:bold; margin-bottom:2px;">Authorised Signature</p>
                    <p style="font-size:9px; font-weight:bold; margin-bottom:0;">Chubb Syariah Insurance Indonesia</p>

                </div>

                <div style="background-color:#00a1b3; padding:20px 20px; margin:0;">&nbsp;</div>

            </td>
        </tr>
    </table>
    ';

    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output($file_path, 'F');

    return $file_path;
}

add_filter('woocommerce_email_attachments', 'attach_sertifikat_asuransi', 10, 4);

function attach_sertifikat_asuransi($attachments, $email_id, $order, $email) {

    if ($email_id !== 'customer_completed_order') {
        return $attachments;
    }

    if (get_post_meta($order->get_id(), '_is_insurance', true) !== 'yes') {
        return $attachments;
    }

    $details = get_post_meta($order->get_id(), '_flight_passenger_details', true);

    if (empty($details)) {
        return $attachments;
    }

    foreach ($details as $passenger) {

        $file_path = generate_sertifikat_pdf($order, $passenger);

        if ($file_path && file_exists($file_path)) {
            $attachments[] = $file_path;
        }
    }

    return $attachments;

}

