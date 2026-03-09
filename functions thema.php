// API SIMPAN HASIL SCAN BARCODE
// ================================
add_action('rest_api_init', function () {
    register_rest_route('scan/v1', '/save', array(
        'methods'  => 'POST',
        'callback' => 'save_scan_to_db',
        'permission_callback' => '__return_true'
    ));
});

function save_scan_to_db($request) {
    global $wpdb;

    error_log("SCAN API HIT");

    $params = $request->get_json_params();
    error_log("JSON PARAMS: " . json_encode($params));

    $barcode = isset($params['barcode']) ? sanitize_text_field($params['barcode']) : '';
    $route = isset($params['route']) ? sanitize_text_field($params['route']) : '';
    $departure_code = isset($params['departure_code']) ? sanitize_text_field($params['departure_code']) : '';
    $arrival_code = isset($params['arrival_code']) ? sanitize_text_field($params['arrival_code']) : '';

    if (!$barcode) {
        return [
            'success' => false,
            'message' => 'Barcode kosong'
        ];
    }

    $table = $wpdb->prefix . "flight_scans";

    $insert = $wpdb->insert($table, [
        'barcode_value' => $barcode,
        'route' => $route,
        'departure_code' => $departure_code,
        'arrival_code' => $arrival_code,
        'type' => 'scan',
        'created_at'    => current_time('mysql')
    ]);

    if (!$insert) {
        error_log("INSERT ERROR: " . $wpdb->last_error);
        return [
            'success' => false,
            'message' => 'Insert gagal',
            'error'   => $wpdb->last_error
        ];
    }

    return [
        'success' => true,
        'message' => 'Data scan berhasil disimpan',
        'id'      => $wpdb->insert_id
    ];
}

// =====================================================
// API SAVE SCAN (STEP 1)
add_action('rest_api_init', function () {
    register_rest_route('scan/v1', '/save', [
        'methods'  => 'POST',
        'callback' => 'save_scan_to_db',
        'permission_callback' => '__return_true'
    ]);
});

// =====================================================
// API UPDATE DATA STEP 2 (MENGISI DATA PESERTA)
add_action('rest_api_init', function () {
    register_rest_route('scan/v1', '/insert-details', [
        'methods'  => 'POST',
        'callback' => 'insert_passenger_details',
        'permission_callback' => '__return_true'
    ]);
});

function insert_passenger_details($request) {
    global $wpdb;

    $params = $request->get_json_params();

    if (!$params || empty($params['scan_id'])) {
        return [
            'success' => false,
            'message' => 'Scan ID wajib diisi'
        ];
    }

    $scan_id = intval($params['scan_id']);
    $table   = $wpdb->prefix . "flight_scans";

    // VALIDASI PARENT HARUS ADA DAN TYPE = scan
    $parent = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id FROM $table WHERE id = %d AND type = 'scan'",
            $scan_id
        )
    );

    if (!$parent) {
        return [
            'success' => false,
            'message' => 'Scan ID tidak ditemukan atau bukan parent'
        ];
    }

    // VALIDASI FIELD WAJIB
    if (empty($params['namaPeserta']) || empty($params['kodePenerbangan'])) {
        return [
            'success' => false,
            'message' => 'Nama peserta dan kode penerbangan wajib diisi'
        ];
    }

    $data = [
        'scan_id'                  => $scan_id,
        'nama_peserta'             => sanitize_text_field($params['namaPeserta']),
        'kode_penerbangan'         => sanitize_text_field($params['kodePenerbangan']),
        'tanggal_penerbangan'      => sanitize_text_field($params['tanggalPenerbangan'] ?? ''),
        'telepon_peserta'          => sanitize_text_field($params['teleponPeserta'] ?? ''),
        'nama_anggota_keluarga'    => sanitize_text_field($params['namaAnggota'] ?? ''),
        'telepon_anggota_keluarga' => sanitize_text_field($params['teleponAnggota'] ?? ''),
        'email'                    => sanitize_email($params['email'] ?? ''),
        'created_at'               => current_time('mysql'),
        'type'                     => 'passenger'
    ];

    $insert = $wpdb->insert(
        $table,
        $data,
        [
            '%d','%s','%s','%s','%s','%s','%s','%s','%s','%s'
        ]
    );

    if (!$insert) {
        return [
            'success' => false,
            'message' => 'Gagal menyimpan data',
            'error'   => $wpdb->last_error
        ];
    }

    return [
        'success' => true,
        'message' => 'Data penumpang berhasil ditambahkan',
        'row_id'  => $wpdb->insert_id
    ];
}

add_action('rest_api_init', function () {
    register_rest_route('scan/v1', '/get-passengers', [
        'methods' => 'GET',
        'callback' => 'get_passenger_list',
        'permission_callback' => '__return_true'
    ]);
});
function get_passenger_list($request) {
    global $wpdb;

    $scan_id = intval($request->get_param('scan_id'));
    $table   = $wpdb->prefix . "flight_scans";

    if (!$scan_id) {
        return [
            "success" => false,
            "count"   => 0,
            "data"    => []
        ];
    }

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $table 
             WHERE scan_id = %d 
             AND type = 'passenger'",
            $scan_id
        ),
        ARRAY_A
    );

    return [
        "success" => true,
        "count"   => count($rows),
        "data"    => $rows
    ];
}

// =====================================================
// API DELETE PASSENGER (untuk menghapus peserta)
// =====================================================
add_action('rest_api_init', function () {
    register_rest_route('scan/v1', '/delete-passenger', [
        'methods' => 'POST',
        'callback' => 'delete_passenger',
        'permission_callback' => '__return_true'
    ]);
});

function delete_passenger($request) {
    global $wpdb;

    $body = json_decode($request->get_body(), true);
    $id = intval($body['id'] ?? 0);

    if (!$id) {
        return [
            "success" => false,
            "message" => "ID peserta tidak valid"
        ];
    }

    $table = $wpdb->prefix . "flight_scans";

    // Hapus peserta berdasarkan ID
    $deleted = $wpdb->delete($table, ['id' => $id], ['%d']);

    if ($deleted) {
        return [
            "success" => true,
            "message" => "Peserta berhasil dihapus"
        ];
    } else {
        return [
            "success" => false,
            "message" => "Gagal menghapus peserta"
        ];
    }
}

// =====================================================
// API GET INVOICE DATA (untuk halaman payment-success)
// =====================================================
add_action('rest_api_init', function () {
    register_rest_route('scan/v1', '/get-invoice', [
        'methods' => 'GET',
        'callback' => 'get_invoice_data',
        'permission_callback' => '__return_true'
    ]);
});

function get_invoice_data($request) {
    global $wpdb;

    $invoice = sanitize_text_field($request->get_param('inv'));

    if (!$invoice) {
        return [
            'success' => false,
            'message' => 'Parameter invoice tidak ditemukan'
        ];
    }

    // =====================================================
    // PRIORITAS 1: Cari di tabel wp_flight_orders (Paylabs)
    // =====================================================
    $flight_orders_table = $wpdb->prefix . "flight_orders";
    $flight_scans_table  = $wpdb->prefix . "flight_scans";

    $order = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $flight_orders_table WHERE invoice = %s",
            $invoice
        ),
        ARRAY_A
    );

    if ($order) {

        // Ambil SEMUA passenger berdasarkan scan_id
        $scan_data = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $flight_scans_table
                 WHERE scan_id = %d
                 AND type = %s
                 ORDER BY id ASC",
                intval($order['scan_id']),
                'passenger'
            ),
            ARRAY_A
        );

        $passengers = [];

        if (!empty($scan_data)) {
            foreach ($scan_data as $p) {
                $passengers[] = [
                    'nama_peserta'        => $p['nama_peserta'] ?? '-',
                    'telepon_peserta'     => $p['telepon_peserta'] ?? '-',
                    'email'               => $p['email'] ?? '-',
                    'kode_penerbangan'    => $p['kode_penerbangan'] ?? '-',
                    'tanggal_penerbangan' => $p['tanggal_penerbangan'] ?? '-',
                ];
            }
        }

        return [
            'success' => true,
            'data' => [
                'invoice'        => $order['invoice'],
                'product_name'   => $order['option_name'],
                'passengers'     => $passengers, // MULTI PASSENGER
                'payment_method' => $order['payment_method'] ?: 'Virtual Account',
                'payment_date'   => $order['created_at'],
                'amount'         => $order['total'],
                'price_per_pax'  => $order['price'],
                'pax'            => $order['pax'],
                'status'         => $order['status']
            ]
        ];
    }

    // =====================================================
    // PRIORITAS 2: WooCommerce Orders
    // =====================================================
    $order_meta_table = $wpdb->prefix . "wc_orders_meta";

    $order_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT order_id FROM $order_meta_table 
             WHERE meta_key = '_invoice_number' 
             AND meta_value = %s",
            $invoice
        )
    );

    if ($order_id) {
        $wc_order = wc_get_order($order_id);

        if ($wc_order) {

            // WooCommerce default hanya 1 billing (anggap 1 passenger)
            $passengers = [
                [
                    'nama_peserta'        => trim($wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name()),
                    'telepon_peserta'     => $wc_order->get_billing_phone(),
                    'email'               => $wc_order->get_billing_email(),
                    'kode_penerbangan'    => $wc_order->get_meta('_kode_penerbangan'),
                    'tanggal_penerbangan' => $wc_order->get_meta('_tanggal_penerbangan'),
                ]
            ];

            return [
                'success' => true,
                'data' => [
                    'invoice'        => $invoice,
                    'product_name'   => $wc_order->get_meta('_product_name') ?: 'Asuransi Kecelakaan Diri Inflight',
                    'passengers'     => $passengers,
                    'payment_method' => $wc_order->get_payment_method_title(),
                    'payment_date'   => $wc_order->get_date_paid()
                        ? $wc_order->get_date_paid()->format('Y-m-d H:i:s')
                        : $wc_order->get_date_created()->format('Y-m-d H:i:s'),
                    'amount'         => $wc_order->get_total(),
                    'pax'            => 1,
                    'status'         => $wc_order->get_status()
                ]
            ];
        }
    }

    return [
        'success' => false,
        'message' => 'Invoice tidak ditemukan'
    ];
}