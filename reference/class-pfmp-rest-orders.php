<?php
// class-pfmp-rest-orders.php

defined('ABSPATH') || exit;

require_once plugin_dir_path(__FILE__) . '/class-pfmp-utils.php';
require_once plugin_dir_path(__FILE__) . '/class-pfmp-query-utils.php';

class PFMP_REST_Orders {
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_rest_api']);
    }
    public function register_rest_api() {
        register_rest_route('pfm-panel/v1', '/orders', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_filtered_orders'],
            'permission_callback' => ['PFMP_Utils', 'can_access_pfm_panel'],
            'args' => [
                'page'          => ['default' => 1, 'sanitize_callback' => 'absint'],
                'per_page'      => ['default' => 10, 'sanitize_callback' => 'absint'],
                'status'        => ['sanitize_callback' => 'sanitize_text_field'],
                'warehouse'     => ['sanitize_callback' => 'sanitize_text_field'],
                'export_status' => ['sanitize_callback' => 'sanitize_text_field'],
                'addr_status'   => ['sanitize_callback' => 'sanitize_text_field'],
                'date_from'     => ['sanitize_callback' => 'sanitize_text_field'],
                'date_to'       => ['sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);



        register_rest_route('pfm-panel/v1', '/orders/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_single_order'],
            'permission_callback' => ['PFMP_Utils', 'can_access_pfm_panel']
        ]);

        register_rest_route('pfm-panel/v1', '/orders/(?P<id>\d+)', [
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => [$this, 'update_order'],
            'permission_callback' => ['PFMP_Utils', 'can_access_pfm_panel']
        ]);

        register_rest_route('pfm-panel/v1', '/orders/by-user/(?P<user_id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_orders_by_user_id'],
            'permission_callback' => ['PFMP_Utils', 'can_access_pfm_panel']
        ]);

        register_rest_route('pfm-panel/v1', '/orders/(?P<order_id>\d+)/revalidate-address', [
            'methods'  => 'POST',
            'callback' => [$this, 'handle_address_revalidation'],
            'permission_callback' => ['PFMP_Utils', 'can_access_pfm_panel']
        ]);


        register_rest_route('pfm-panel/v1', '/orders/(?P<order_id>\d+)/export-to-warehouse', [
            'methods'  => 'POST',
            'callback' => [$this, 'export_to_warehouse'],
            'permission_callback' => ['PFMP_Utils', 'can_access_pfm_panel']
        ]);

        register_rest_route('pfm-panel/v1', '/orders/bulk', [
            'methods'  => 'POST',
            'callback' => [$this, 'handle_bulk_action'],
            'permission_callback' => ['PFMP_Utils', 'can_access_pfm_panel'],
        ]);

        register_rest_route('pfm-panel/v1', '/orders/(?P<id>\d+)/edit', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'edit_order_items'],
            'permission_callback' => ['PFMP_Utils', 'can_access_pfm_panel']
        ]);

        register_rest_route('pfm-panel/v1', '/products-by-category', [
            'methods'  => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_products_by_category'],
            'permission_callback' => ['PFMP_Utils', 'can_access_pfm_panel'],
        ]);

        register_rest_route('pfm-panel/v1', '/orders/(?P<order_id>\d+)/notes', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_order_notes'],
            'permission_callback' => ['PFMP_Utils', 'can_access_pfm_panel'],
        ]);


        register_rest_route(
            'pfm-panel/v1',
            '/orders/(?P<id>\d+)/restore',
            [
                'methods'  => 'POST',
                'callback' => [$this, 'restore_order'],
                'permission_callback' => ['PFMP_Utils', 'can_access_pfm_panel'],
            ]
        );

        register_rest_route('pfm-panel/v1', '/customers/search', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'search_customers_by'],
            'permission_callback' => ['PFMP_Utils', 'can_access_pfm_panel'],
            'args' => [
                'type' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'value' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);


        register_rest_route('pfm-panel/v1', '/orders/latest-id', [
            'methods'  => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_latest_order_id'],
            'permission_callback' => ['PFMP_Utils', 'can_access_pfm_panel'],
        ]);

        register_rest_route('pfm-panel/v1', '/orders/braintree-info', [
            'methods'             => WP_REST_Server::CREATABLE, // POST
            'callback'            => [$this, 'get_braintree_info'],
            'permission_callback' => ['PFMP_Utils', 'can_access_pfm_panel'],
        ]);

        register_rest_route('pfm-panel/v1', '/orders/(?P<order_id>\d+)/preview', [
            'methods'  => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_order_preview'],
            'permission_callback' => ['PFMP_Utils', 'can_access_pfm_panel'],
        ]);

        register_rest_route('pfm-panel/v1', '/orders/(?P<order_id>\d+)/narvar-tracking-url', [
            'methods'  => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_narvar_tracking_url'],
            'permission_callback' => ['PFMP_Utils', 'can_access_pfm_panel'],
        ]);

        register_rest_route('pfm-panel/v1', '/orders/(?P<id>\d+)/resend-email', [
            'methods'             => WP_REST_Server::CREATABLE, // POST
            'callback'            => [$this, 'resend_order_email'],
            'permission_callback' => ['PFMP_Utils', 'can_access_pfm_panel'],
            'args'                => [
                'type' => ['sanitize_callback' => 'sanitize_text_field'], // processing | completed | invoice
            ],
        ]);

        register_rest_route('pfm-panel/v1', '/orders/status-counts', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_order_status_counts'],
            'permission_callback' => ['PFMP_Utils', 'can_access_pfm_panel'],
        ]);
    }


    public function get_order_status_counts(WP_REST_Request $request) {
        $statuses = wc_get_order_statuses();
        $counts   = [];

        foreach ($statuses as $status_key => $label) {
            $status = str_replace('wc-', '', $status_key);
            $counts[$status] = wc_orders_count($status);
        }

        $counts['all'] = array_sum($counts);

        return rest_ensure_response($counts);
    }


    public function resend_order_email(WP_REST_Request $request) {
        $order_id = absint($request['id']);
        $type     = $request->get_param('type') ?: 'processing'; // processing|completed|invoice
        $force    = (bool) $request->get_param('force');         // optional
        $force = true;

        $order = wc_get_order($order_id);
        if (! $order) {
            return new WP_Error('not_found', 'Order not found', ['status' => 404]);
        }

        $current_user = wp_get_current_user();
        $admin_name   = $current_user->display_name ?: 'Admin';

        $type_to_id = [
            'processing' => 'customer_processing_order',
            'completed'  => 'customer_completed_order',
            'invoice'    => 'customer_invoice',
        ];
        $type_to_class = [
            'processing' => 'WC_Email_Customer_Processing_Order',
            'completed'  => 'WC_Email_Customer_Completed_Order',
            'invoice'    => 'WC_Email_Customer_Invoice',
        ];

        $email_id    = $type_to_id[$type] ?? 'customer_processing_order';
        $email_class = $type_to_class[$type] ?? 'WC_Email_Customer_Processing_Order';

        $mailer = WC()->mailer();
        if (! $mailer) {
            return new WP_Error('mailer_unavailable', 'Mailer not available', ['status' => 500]);
        }

        $emails = $mailer->get_emails();

        // summarize available templates for debugging
        $available = [];
        foreach ((array) $emails as $key => $obj) {
            $available[] = [
                'key'     => $key,
                'id'      => $obj->id ?? null,
                'class'   => get_class($obj),
                'enabled' => method_exists($obj, 'is_enabled') ? ($obj->is_enabled() ? 'yes' : 'no') : 'n/a',
            ];
        }

        // find target by id first, then by class key
        $target = null;
        foreach ((array) $emails as $key => $email_obj) {
            if (isset($email_obj->id) && $email_obj->id === $email_id) {
                $target = $email_obj;
                break;
            }
        }
        if (! $target && isset($emails[$email_class])) {
            $target = $emails[$email_class];
        }

        if (! $target) {
            return new WP_Error(
                'email_not_found',
                'Email template not available',
                ['status' => 500, 'available' => $available]
            );
        }

        $was_enabled = method_exists($target, 'is_enabled') ? ($target->is_enabled() ? 'yes' : 'no') : 'n/a';

        // Optionally force-enable
        $restore_enabled = null;
        if ($force && method_exists($target, 'is_enabled') && ! $target->is_enabled()) {
            $restore_enabled = $target->enabled; // 'yes'/'no'
            $target->enabled = 'yes';
        }

        try {
            if ($email_id === 'customer_invoice') {
                // safer signature for invoice
                $target->trigger($order_id, $order);
            } else {
                $target->trigger($order_id);
            }

            if ($restore_enabled !== null) {
                $target->enabled = $restore_enabled;
            }

            $order->add_order_note(sprintf(
                "📧 Resent '%s' email to customer by %s via PFM Panel.",
                $email_id,
                $admin_name
            ));
            $order->save();
            PFMP_Utils::log_admin_action('email_resend', 'order', "Resent '{$email_id}' email for order #{$order_id}");

            return rest_ensure_response(['success' => true, 'email_id' => $email_id]);
        } catch (Throwable $e) {
            if ($restore_enabled !== null) {
                $target->enabled = $restore_enabled;
            }
            PFMP_Utils::log("❌ [ResendEmail] Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return new WP_Error('email_send_failed', $e->getMessage(), ['status' => 500]);
        }
    }
    public function get_order_preview(WP_REST_Request $request) {
        $order_id = absint($request['order_id']);
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('not_found', 'Order not found', ['status' => 404]);
        }

        $items = [];
        foreach ($order->get_items('line_item') as $item) {
            $product = $item->get_product();
            $items[] = [
                'id'       => $item->get_id(),
                'name'     => $product ? $product->get_name() : $item->get_name(),
                'quantity' => $item->get_quantity(),
                'total_raw' => $item->get_total(),
                'currency'  => $order->get_currency(),
                'image' => wp_get_attachment_image_url($product->get_image_id(), 'woocommerce_thumbnail'),
            ];
        }

        return rest_ensure_response(['items' => $items]);
    }

    public function get_narvar_tracking_url(WP_REST_Request $request) {
        $order_id = absint($request['order_id']);
        if (!$order_id) {
            return new WP_Error('invalid_order', 'Invalid order ID', ['status' => 400]);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('not_found', 'Order not found', ['status' => 404]);
        }

        if (!class_exists('Narvar_Tracking_Integration')) {
            return new WP_Error('narvar_unavailable', 'Narvar integration not available', ['status' => 500]);
        }

        try {
            $narvar_instance = Narvar_Tracking_Integration::instance();
            if (!method_exists($narvar_instance, 'get_narvar_tracking_url')) {
                return new WP_Error('method_unavailable', 'Narvar tracking URL method not available', ['status' => 500]);
            }

            $tracking_url = $narvar_instance->get_narvar_tracking_url($order_id);

            if (empty($tracking_url)) {
                return rest_ensure_response([
                    'success' => false,
                    'tracking_url' => null,
                    'message' => 'No tracking URL available for this order',
                ]);
            }

            return rest_ensure_response([
                'success' => true,
                'tracking_url' => $tracking_url,
            ]);
        } catch (Throwable $e) {
            return new WP_Error('narvar_error', $e->getMessage(), ['status' => 500]);
        }
    }

    private function get_avs_text($code) {
        $map = [
            'M' => 'matches',                         // Street/Postal matches
            'N' => 'does not match',                  // Street/Postal does not match
            'U' => 'not verified',                    // Received but not verified
            'I' => 'not provided',                    // No info provided
            'A' => 'not applicable',                  // Not supported for transaction type
            'B' => 'skipped',                         // AVS skipped
            'S' => 'bank does not support',           // Bank doesn’t participate
            'E' => 'system error',                    // AVS system error
        ];
        return $map[$code] ?? $code;
    }

    private function get_cvv_text($code) {
        $map = [
            'M' => 'matches',                         // CVV matches
            'N' => 'does not match',                  // CVV does not match
            'U' => 'not verified',                    // Received but not verified
            'I' => 'not provided',                    // No CVV provided
            'S' => 'does not participate',            // Bank doesn’t participate
            'A' => 'not applicable',                  // Not supported for transaction type
            'B' => 'skipped',                         // CVV skipped
        ];
        return $map[$code] ?? $code;
    }

    public function get_braintree_info(WP_REST_Request $request) {
        $transaction_id = sanitize_text_field($request->get_param('transaction_id'));
        if (!$transaction_id) {
            return rest_ensure_response(['transaction' => null, 'disputes' => []]);
        }

        try {
            $this->configure_braintree();

            // Try to load the transaction (primary source of truth for disputes)
            $tx = null;
            try {
                $t = \Braintree\Transaction::find($transaction_id);

                // --- Transaction: only the fields you requested ---
                $tx = [
                    'id'            => $t->id ?? null,
                    'status'        => $t->status ?? null,
                    'avs'           => [
                        'street' => [
                            'code' => $t->avsStreetAddressResponseCode ?? null,
                            'text' => $this->get_avs_text($t->avsStreetAddressResponseCode ?? null),
                        ],
                        'postal' => [
                            'code' => $t->avsPostalCodeResponseCode ?? null,
                            'text' => $this->get_avs_text($t->avsPostalCodeResponseCode ?? null),
                        ],
                    ],
                    'cvv_response'  => [
                        'code' => $t->cvvResponseCode ?? null,
                        'text' => $this->get_cvv_text($t->cvvResponseCode ?? null),
                    ],
                    'risk'          => (isset($t->riskData) && $t->riskData) ? [
                        'decision' => $t->riskData->decision ?? null,
                        'id'       => $t->riskData->id ?? null,
                        'score'    => $t->riskData->score ?? null,
                    ] : null,
                ];

                // --- Disputes: taken directly from the transaction object ---
                $disputes_data = (isset($t->disputes) && is_array($t->disputes))
                    ? array_map(function ($d) {
                        return [
                            'id'            => $d->id ?? null,
                            'status'        => $d->status ?? null,
                            'reason'        => $d->reason ?? null,
                            'amount'        => $d->amount ?? null,
                            'currency'      => $d->currencyIsoCode ?? null,
                            'received_date' => isset($d->receivedDate) ? $d->receivedDate->format('Y-m-d') : null,
                            'reply_by'      => isset($d->replyByDate) ? $d->replyByDate->format('Y-m-d') : null,
                            'transaction_id' => $d->transactionDetails->id ?? null,
                        ];
                    }, $t->disputes)
                    : [];

                return rest_ensure_response([
                    'transaction' => $tx,
                    'disputes'    => $disputes_data,
                ]);
            } catch (\Braintree\Exception\NotFound $e) {
                // Optional fallback: if the transaction isn't found, try dispute search anyway
                $fallback_disputes = [];
                $res = \Braintree\Dispute::search([
                    \Braintree\DisputeSearch::transactionId()->is($transaction_id),
                ]);
                foreach ($res as $d) {
                    $fallback_disputes[] = [
                        'id'            => $d->id ?? null,
                        'status'        => $d->status ?? null,
                        'reason'        => $d->reason ?? null,
                        'amount'        => $d->amount ?? null,
                        'currency'      => $d->currencyIsoCode ?? null,
                        'received_date' => isset($d->receivedDate) ? $d->receivedDate->format('Y-m-d') : null,
                        'reply_by'      => isset($d->replyByDate) ? $d->replyByDate->format('Y-m-d') : null,
                        'transaction_id' => $d->transactionDetails->id ?? null,
                    ];
                }

                return rest_ensure_response([
                    'transaction' => null,
                    'disputes'    => $fallback_disputes,
                ]);
            }
        } catch (\Exception $e) {
            return new WP_Error('braintree_error', $e->getMessage(), ['status' => 500]);
        }
    }


    private function configure_braintree() {
        $cfg = get_option('woocommerce_braintree_api_settings');
        if (!$cfg) return;

        $env = $cfg['environment'];
        \Braintree\Configuration::environment($env);
        \Braintree\Configuration::merchantId($cfg[$env . '_merchant_id']);
        \Braintree\Configuration::publicKey($cfg[$env . '_public_key']);
        \Braintree\Configuration::privateKey($cfg[$env . '_private_key']);
    }

    public function get_latest_order_id(WP_REST_Request $request) {
        try {
            $orders = wc_get_orders([
                'limit'   => 1,
                'orderby' => 'date',
                'order'   => 'DESC',
                'return'  => 'ids',
                'type'     => 'shop_order'
            ]);

            $latest_id = $orders[0] ?? 0;

            return rest_ensure_response(['latest_id' => $latest_id]);
        } catch (Throwable $e) {
            return new WP_Error('latest_id_fetch_failed', 'Failed to fetch latest order ID.', ['status' => 500]);
        }
    }


    public function search_customers_by(WP_REST_Request $request) {
        $type  = $request->get_param('type');
        $value = $request->get_param('value');

        if (empty($type) || empty($value)) {
            return new WP_Error('invalid_params', 'Search type and value are required.', ['status' => 400]);
        }

        $results = [];
        $users   = [];

        if ($type === 'email') {
            $email = sanitize_email($value);
            $users = get_users([
                'search'         => $email,
                'search_columns' => ['user_email'],
                'number'         => 10,
                'fields'         => ['ID', 'user_email', 'display_name'],
            ]);
        } elseif ($type === 'id') {
            $user = get_userdata(absint($value));
            if ($user) {
                $users[] = $user;
            }
        } elseif ($type === 'name') {
            $users = get_users([
                'search'         => '*' . esc_attr($value) . '*',
                'search_columns' => ['display_name'],
                'number'         => 10,
                'fields'         => ['ID', 'user_email', 'display_name'],
            ]);
        } else {
            return new WP_Error('unsupported_type', 'Unsupported search type.', ['status' => 400]);
        }

        foreach ($users as $user) {
            $user_id = $user->ID;

            $billing_fields = [
                'first_name',
                'last_name',
                'email',
                'phone',
                'address_1',
                'address_2',
                'city',
                'state',
                'postcode',
                'country',
            ];
            $shipping_fields = [
                'first_name',
                'last_name',
                'phone',
                'address_1',
                'address_2',
                'city',
                'state',
                'postcode',
                'country',
            ];

            $billing = [];
            foreach ($billing_fields as $field) {
                $billing[$field] = get_user_meta($user_id, 'billing_' . $field, true);
            }

            $shipping = [];
            foreach ($shipping_fields as $field) {
                $shipping[$field] = get_user_meta($user_id, 'shipping_' . $field, true);
            }

            $results[] = [
                'id'      => $user_id,
                'email'   => $user->user_email,
                'name'    => $user->display_name,
                'billing' => $billing,
                'shipping' => $shipping,
            ];
        }

        return rest_ensure_response($results);
    }

    public function restore_order($request) {
        $current_user = wp_get_current_user();
        $admin_name = $current_user->display_name ?? 'Admin';
        $admin_roles = $current_user->roles ?? [];

        $order_id = (int) $request['id'];

        do_action('restore_archive_order', $order_id);

        $order = wc_get_order($order_id);
        $order->add_order_note('Order restored from archive by ' . $admin_name);

        PFMP_Utils::log_admin_action('restore', 'order', "Restored order #{$order_id} from archive");

        return rest_ensure_response(['success' => true]);
    }

    function get_order_notes(WP_REST_Request $request) {
        $order_id = absint($request['order_id']);
        if (!$order_id) {
            return new WP_Error('invalid_order', 'Invalid order ID', ['status' => 400]);
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('not_found', 'Order not found', ['status' => 404]);
        }

        $notes = wc_get_order_notes([
            'order_id' => $order_id,
            'type'     => 'any',
            'orderby'  => 'date_created',
            'order'    => 'DESC',
        ]);
        $max_length = 300;
        $formatted = [];
        foreach ($notes as $note) {
            // Use raw content as-is (with HTML)
            $content = (string) $note->content;

            // Apply length limit, but don't touch formatting
            if ($max_length > 0 && strlen($content) > $max_length) {
                // Use mb_substr if available, otherwise fall back to substr
                if (function_exists('mb_substr')) {
                    $content = mb_substr($content, 0, $max_length) . '…';
                } else {
                    $content = substr($content, 0, $max_length) . '…';
                }
            }

            $formatted[] = [
                'id'           => $note->id,
                'note'         => $content,
                // Keep consistent with refunds: add +5 hours to align UI timing expectations.
                'date_created' => $note->date_created ? (function () use ($note) {
                    $dt = clone $note->date_created;
                    $dt->modify('+5 hours');
                    return $dt->date('c');
                })() : '',
                'author'       => $note->added_by ?: '',
                'is_customer'  => $note->customer_note ? true : false,
                'type'         => $note->customer_note ? 'customer' : 'internal',
            ];
        }

        return $formatted;
    }

    public function get_products_by_category(WP_REST_Request $request) {
        $categories = [
            'face' => [7, 2213887, 100362, 100370, 616257, 1077388, 2299802, 3312652],
            'hair' => [1860895, 570671, 216928, 1068419, 616215],
            'body' => [1646173, 3276357, 2475444, 1450671, 1495139, 216919, 3008280],
            'wellness' => [2293541],
            'bundles' => [1813624, 250223, 250209, 1868710, 616306, 1607485, 2769533, 2769100, 3279775, 3536797],
        ];
        $labels = [
            'face' => 'Face',
            'hair' => 'Hair',
            'body' => 'Body',
            'wellness' => 'Wellness',
            'bundles' => 'Bundles',
        ];

        $result = [];
        foreach ($categories as $cat_key => $product_ids) {
            $group = [
                'key' => $cat_key,
                'label' => $labels[$cat_key] ?? $cat_key,
                'products' => [],
            ];
            foreach ($product_ids as $pid) {
                $product = wc_get_product($pid);
                if (!$product) continue;
                $group['products'][] = [
                    'id' => $product->get_id(),
                    'name' => $product->get_name(),
                    'price' => wc_get_price_to_display($product),
                    'image' => wp_get_attachment_url($product->get_image_id()),
                    'sku' => $product->get_sku(),
                ];
            }
            $result[] = $group;
        }
        return rest_ensure_response($result);
    }

    public function edit_order_items(WP_REST_Request $request) {
        $order_id = absint($request['id']);
        $params = $request->get_json_params();

        $current_user = wp_get_current_user();
        $admin_name = $current_user->display_name ?? 'Admin';
        $admin_roles = $current_user->roles ?? [];

        if (!$order_id || !($order = wc_get_order($order_id))) {
            return new WP_Error('invalid_order', 'Order not found', ['status' => 404]);
        }

        $updated = false;


        // Update items
        if (!empty($params['items']) && is_array($params['items'])) {
            foreach ($params['items'] as $item_data) {
                if (empty($item_data['id'])) continue;
                $item = $order->get_item($item_data['id']);
                if (!$item || $item->is_type('fee') || $item->is_type('shipping')) continue;


                if (isset($item_data['quantity'])) {
                    $item->set_quantity((int)$item_data['quantity']);
                }
                if (isset($item_data['total'])) {
                    $item->set_total(floatval($item_data['total']));
                }
                if (isset($item_data['tax'])) {
                    $this->pfmp_set_single_tax($item, $item_data['tax'], 'line_item');
                }
                $item->save();
                $updated = true;
            }
        }

        // Update fees
        if (!empty($params['fees']) && is_array($params['fees'])) {
            foreach ($params['fees'] as $fee_data) {
                if (empty($fee_data['id'])) continue;
                $item = $order->get_item($fee_data['id']);
                if (!$item || !$item->is_type('fee')) continue;
                if (isset($fee_data['name'])) {
                    $item->set_name(wp_strip_all_tags($fee_data['name']));
                }
                if (isset($fee_data['total'])) {
                    $item->set_total(floatval($fee_data['total']));
                }
                if (isset($fee_data['tax'])) {
                    $this->pfmp_set_single_tax($item, $fee_data['tax'], 'fee');
                }
                $item->save();
                $updated = true;
            }
        }

        // Update shipping
        if (!empty($params['shipping']) && is_array($params['shipping'])) {
            foreach ($params['shipping'] as $ship_data) {
                if (empty($ship_data['id'])) continue;
                $item = $order->get_item($ship_data['id']);
                if (!$item || !$item->is_type('shipping')) continue;

                if (isset($ship_data['method_title'])) {
                    $item->set_name(wp_strip_all_tags($ship_data['method_title']));
                }

                if (isset($ship_data['method_id'])) {
                    // Parse "flat_rate:10" into method_id and instance_id
                    $parts = explode(':', $ship_data['method_id']);
                    $method_id = $parts[0] ?? '';
                    $instance_id = isset($parts[1]) ? absint($parts[1]) : 0;

                    $item->update_meta_data('method_id', $method_id);
                    $item->update_meta_data('instance_id', $instance_id);

                    // Also update Woo’s expected keys
                    $item->update_meta_data('method_id', $method_id);
                    $item->update_meta_data('instance_id', $instance_id);
                    $item->update_meta_data('shipping_method', $method_id);
                    $item->update_meta_data('_method_id', $method_id);
                    $item->update_meta_data('_instance_id', $instance_id);
                }

                if (isset($ship_data['total'])) {
                    $item->set_total(floatval($ship_data['total']));
                }

                if (isset($ship_data['tax'])) {
                    $this->pfmp_set_single_tax($item, $ship_data['tax'], 'shipping');
                }

                $item->save();
                $updated = true;
            }
        }


        // Sum taxes by rate_id across all items/fees/shipping
        $order_item_tax_totals = [];
        foreach ($order->get_items(array('line_item', 'fee', 'shipping')) as $item) {
            $taxes = $item->get_taxes()['total'] ?? [];
            foreach ($taxes as $rate_id => $amount) {
                if (!isset($order_item_tax_totals[$rate_id])) {
                    $order_item_tax_totals[$rate_id] = 0;
                }
                $order_item_tax_totals[$rate_id] += floatval($amount);
            }
        }


        // Update the order's tax lines (tax items)
        foreach ($order->get_items('tax') as $tax_item_id => $tax_item) {
            $rate_id = $tax_item->get_rate_id();
            $new_tax_total = isset($order_item_tax_totals[$rate_id]) ? $order_item_tax_totals[$rate_id] : 0;
            $tax_item->set_tax_total($new_tax_total);
            $tax_item->set_shipping_tax_total(0); // set if needed
            $tax_item->save();
        }

        // Add new products (line items)
        if (!empty($params['new_items']) && is_array($params['new_items'])) {
            foreach ($params['new_items'] as $item_data) {
                // Required: product_id, quantity, total, tax
                $product_id = isset($item_data['product_id']) ? absint($item_data['product_id']) : 0;
                $quantity   = isset($item_data['quantity']) ? intval($item_data['quantity']) : 1;
                $total      = isset($item_data['total']) ? floatval($item_data['total']) : 0;
                $tax        = isset($item_data['tax']) ? floatval($item_data['tax']) : 0;

                if (!$product_id || $quantity < 1) continue;

                $order_item_id = $order->add_product(
                    wc_get_product($product_id),
                    $quantity,
                    [
                        'subtotal' => $total,
                        'total' => $total,
                    ]
                );

                if ($order_item_id && $tax !== null) {
                    $item = $order->get_item($order_item_id);
                    if ($item) {
                        $this->pfmp_set_single_tax($item, $tax, 'line_item');
                        $item->save();
                    }
                }
                $updated = true;
            }
        }



        // ---- REMOVE ITEMS ----
        if (!empty($params['removed_items']) && is_array($params['removed_items'])) {
            foreach ($params['removed_items'] as $item_id) {
                $item = $order->get_item($item_id);
                if ($item && $item->is_type('line_item')) {
                    $order->remove_item($item_id);
                    $updated = true;
                }
            }
        }


        // Add new fees
        if (!empty($params['new_fees']) && is_array($params['new_fees'])) {
            foreach ($params['new_fees'] as $fee_data) {
                $fee_name  = isset($fee_data['name']) ? wp_strip_all_tags($fee_data['name']) : '';
                $fee_total = isset($fee_data['total']) ? floatval($fee_data['total']) : 0;
                $fee_tax   = isset($fee_data['tax']) ? floatval($fee_data['tax']) : 0;

                if (empty($fee_name)) {
                    continue;
                }

                $item = new WC_Order_Item_Fee();
                $item->set_name($fee_name);
                $item->set_total($fee_total);

                // 1. Add the item to the order first to associate the order object
                $order->add_item($item);

                // 2. Now set tax
                if ($fee_tax !== null) {
                    $this->pfmp_set_single_tax($item, $fee_tax, 'fee');
                }

                // 3. Save the fee item
                $item->save();
                $updated = true;
            }
        }


        // Remove fees
        if (!empty($params['removed_fees']) && is_array($params['removed_fees'])) {
            foreach ($params['removed_fees'] as $fee_id) {
                $item = $order->get_item($fee_id);
                if ($item && $item->is_type('fee')) {
                    $order->remove_item($fee_id);
                    $updated = true;
                }
            }
        }

        $auto_tax = !empty($params['auto_tax']);

        if ($updated) {
            $order->add_order_note('Order items were edited by ' . $admin_name);
            if ($auto_tax) {
                /*
                if (class_exists('WC_Complyt_Tax') && method_exists('WC_Complyt_Tax', 'init') && method_exists('WC_Complyt_Tax', 'get_instance')) {
                    WC_Complyt_Tax::init();
                    $complyt = WC_Complyt_Tax::get_instance();

                    if ($complyt && method_exists($complyt, 'set_order_id')) {
                        $complyt->set_order_id($order_id);
                    }
                }

                $billing = [
                    'country'  => $order->get_billing_country(),
                    'state'    => $order->get_billing_state(),
                    'postcode' => $order->get_billing_postcode(),
                    'city'     => $order->get_billing_city(),
                ];

                $order->calculate_taxes($billing);
                $order->calculate_totals(false);
                $order->save();
                */
                PFMP_Utils::log("🧾 Auto-tax recalculation triggered for order ID {$order_id} by {$admin_name}.");

                if (class_exists('WC_Complyt_Tax') && method_exists('WC_Complyt_Tax', 'init')) WC_Complyt_Tax::init();
                apply_filters('get_rate_for_order_or_subscription', $order_id, null);
            } else {
                // --- BEGIN: Remove orphaned tax lines and recalc tax totals ---
                $order_item_tax_totals = [];
                foreach ($order->get_items(array('line_item', 'fee', 'shipping')) as $item) {
                    $taxes = $item->get_taxes()['total'] ?? [];
                    foreach ($taxes as $rate_id => $amount) {
                        if (!isset($order_item_tax_totals[$rate_id])) {
                            $order_item_tax_totals[$rate_id] = 0;
                        }
                        $order_item_tax_totals[$rate_id] += floatval($amount);
                    }
                }

                foreach ($order->get_items('tax') as $tax_item_id => $tax_item) {
                    $rate_id = $tax_item->get_rate_id();
                    $new_tax_total = isset($order_item_tax_totals[$rate_id]) ? $order_item_tax_totals[$rate_id] : 0;
                    if ($new_tax_total == 0) {
                        // Remove orphaned tax line
                        $order->remove_item($tax_item_id);
                    } else {
                        $tax_item->set_tax_total($new_tax_total);
                        $tax_item->set_shipping_tax_total(0); // set if needed
                        $tax_item->save();
                    }
                }
                // --- END: Remove orphaned tax lines and recalc tax totals ---

                // Manually set order total to sum of all line items, fees, shipping, and tax
                $total_fees = 0;
                foreach ($order->get_items('fee') as $fee_item) {
                    $total_fees += floatval($fee_item->get_total());
                }
                $total_shipping = 0;
                foreach ($order->get_items('shipping') as $shipping_item) {
                    $total_shipping += floatval($shipping_item->get_total());
                }
                $total_tax = 0;
                foreach ($order->get_items('tax') as $tax_item) {
                    $total_tax += floatval($tax_item->get_tax_total());
                }
                $total_products = 0;
                foreach ($order->get_items('line_item') as $item) {
                    $total_products += floatval($item->get_total());
                }

                $order_total = $total_products + $total_fees + $total_shipping + $total_tax;

                $order->set_total($order_total);
                $order->save();

                PFMP_Utils::log_admin_action('edit_items', 'order', "Edited items/fees/shipping on order #{$order_id}");
            }
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'Order updated successfully',
        ]);
    }

    private function pfmp_set_single_tax($item, $new_total_tax, $context = '') {
        $taxes = $item->get_taxes();
        $rate_ids = array_keys($taxes['total']);
        $first_rate_id = $rate_ids ? $rate_ids[0] : null;
        if (!$first_rate_id) {
            $order_taxes = $item->get_order()->get_items('tax');
            if (!empty($order_taxes)) {
                $first_rate_id = (string) reset($order_taxes)->get_rate_id();
            }
        }
        if (!$first_rate_id) $first_rate_id = 1;
        $taxes['total']    = [$first_rate_id => floatval($new_total_tax)];
        $taxes['subtotal'] = [$first_rate_id => floatval($new_total_tax)];
        $item->set_taxes($taxes);
    }

    public function handle_bulk_action(WP_REST_Request $request) {
        $ids = $request->get_param('ids');
        $action = sanitize_text_field($request->get_param('action'));
        $value = sanitize_text_field($request->get_param('value'));

        $current_user = wp_get_current_user();
        $admin_name = $current_user->display_name ?? 'Admin';
        $admin_roles = $current_user->roles ?? [];

        if (!is_array($ids) || empty($action)) {
            return new WP_Error('invalid_request', 'Invalid payload.', ['status' => 400]);
        }

        global $wex_plugin;
        $has_validation = isset($wex_plugin) && method_exists($wex_plugin, 'validate_order_address');

        $results = [];
        $skipped = []; // Track orders skipped due to validation failures
        $exported_order_ids = []; // Track successfully exported orders for bulk logging

        foreach ($ids as $id) {
            $order = wc_get_order(absint($id));
            if (!$order) continue;

            if ($action === 'change_status') {
                $order->set_status($value);
                $order->add_order_note("Status changed to '$value' by $admin_name via bulk action.");
                $order->save();
                PFMP_Utils::log_admin_action('bulk_update', 'order', "Changed status of order #{$id} to '{$value}'");

                $results[] = $id;
            }

            if ($action === 'export_to_warehouse') {
                $warehouse_slug = sanitize_text_field($value);
                $decide = $warehouse_slug === 'decide_based_on_rules';

                if ($decide) {
                    $warehouse_slug = \WEX\Utils::get_warehouse_for_order($id);
                }

                if ($warehouse_slug) {
                    // Validate address before exporting
                    $validation_passed = true;
                    $validation_message = '';

                    if ($has_validation) {
                        $validation_result = $wex_plugin->validate_order_address($id);

                        if ($validation_result->is_valid === 'false') {
                            $validation_passed = false;
                            $validation_message = $validation_result->message ?? 'Invalid address';

                            // Add note to order about validation failure
                            $order->add_order_note(
                                sprintf(
                                    __('Bulk export skipped: Address validation failed - %s', 'warehouse-export'),
                                    $validation_message
                                )
                            );
                            $order->save();

                            $skipped[] = [
                                'id' => $id,
                                'reason' => 'address_validation_failed',
                                'message' => $validation_message
                            ];
                        }
                    }

                    // Only export if validation passed (or validation is not available)
                    if ($validation_passed) {
                        $response = $wex_plugin->send_order_to_warehouse($warehouse_slug, $id);

                        if (!empty($response['status']) && $response['status'] === 'success') {
                            $order->update_meta_data('warehouse_to_export', $warehouse_slug);
                            $order->update_meta_data('warehouse_export_status', 'exported');
                            $order->add_order_note("Bulk-exported to {$warehouse_slug} by $admin_name.");
                            $order->save();

                            // Collect order ID for bulk logging instead of logging individually
                            $exported_order_ids[] = $id;
                            $results[] = $id;
                        } else {
                            // Export failed (not validation, but warehouse API issue)
                            $error_msg = !empty($response['message']) ? $response['message'] : 'Unknown error';
                            $skipped[] = [
                                'id' => $id,
                                'reason' => 'export_failed',
                                'message' => $error_msg
                            ];
                        }
                    }
                }
            }
        }

        // Log all exported orders in a single admin action log entry
        if ($action === 'export_to_warehouse' && !empty($exported_order_ids)) {
            $warehouse_slug = sanitize_text_field($value);
            if ($warehouse_slug === 'decide_based_on_rules') {
                $warehouse_slug = 'warehouse (auto-determined)';
            }

            $order_count = count($exported_order_ids);
            $order_ids_str = implode(', ', $exported_order_ids);

            PFMP_Utils::log_admin_action(
                'bulk_export',
                'order',
                "Bulk exported {$order_count} order(s) to '{$warehouse_slug}': {$order_ids_str}"
            );
        }

        $response_data = [
            'success' => true,
            'updated' => $results,
        ];

        // Include skipped orders info if any were skipped
        if (!empty($skipped)) {
            $response_data['skipped'] = $skipped;
        }

        return rest_ensure_response($response_data);
    }

    public function export_to_warehouse(WP_REST_Request $request) {
        $order_id = absint($request->get_param('order_id'));
        $warehouse_slug = sanitize_text_field($request->get_param('warehouse'));

        $current_user = wp_get_current_user();
        $admin_name = $current_user->display_name ?? 'Admin';
        $admin_roles = $current_user->roles ?? [];

        if ($warehouse_slug == "decide_based_on_rules") $decide = true;
        else $decide = false;

        if ($decide) $warehouse_slug = \WEX\Utils::get_warehouse_for_order($order_id);

        if (!$order_id || !$warehouse_slug) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Invalid order ID or warehouse slug.',
            ], 400);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        global $wex_plugin;
        if (!isset($wex_plugin) || !method_exists($wex_plugin, 'send_order_to_warehouse')) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Warehouse export plugin is not available.',
            ], 500);
        }

        // Validate address before exporting
        if (method_exists($wex_plugin, 'validate_order_address')) {
            $validation_result = $wex_plugin->validate_order_address($order_id);

            // Check if validation failed
            if ($validation_result->is_valid === 'false') {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Address validation failed: ' . ($validation_result->message ?? 'Invalid address'),
                ], 400);
            }
        }

        $response = $wex_plugin->send_order_to_warehouse($warehouse_slug, $order_id);

        if (!empty($response['status']) && $response['status'] === 'success') {
            $order->update_meta_data('warehouse_to_export', $warehouse_slug);
            $order->update_meta_data('warehouse_export_status', 'exported');

            $note = sprintf(__('Order manually exported to %s by %s.', 'warehouse-export'), $warehouse_slug, $admin_name);
            $order->add_order_note($note);
            $order->save();

            PFMP_Utils::log_admin_action('export', 'order', "Exported order #{$order_id} to warehouse '{$warehouse_slug}'");

            return new WP_REST_Response([
                'success' => true,
                'message' => 'Order was manually exported successfully!',
            ]);
        }

        $error_msg = !empty($response['message']) ? $response['message'] : 'Unknown error.';
        return new WP_REST_Response([
            'success' => false,
            'message' => "Export failed: {$error_msg}",
        ], 400);
    }


    public function handle_address_revalidation(WP_REST_Request $request) {
        // 👀 Start of request

        $order_id = absint($request->get_param('order_id'));
        $params = $request->get_json_params();
        $force = !empty($params['force']);

        $current_user = wp_get_current_user();
        $admin_name = $current_user->display_name ?? 'Admin';
        $admin_roles = $current_user->roles ?? [];

        if (!$order_id) {

            return new WP_REST_Response(['error' => 'Invalid order ID'], 400);
        }

        if ($force) {

            $order = wc_get_order($order_id);
            if (!$order) {

                return new WP_REST_Response(['error' => 'Order not found'], 404);
            }

            $order->update_meta_data('validate_address_status', 'valid');
            $order->add_order_note(__("Address status manually forced to VALID by $admin_name.", 'warehouse-export'));
            $order->save();
            PFMP_Utils::log_admin_action('address_force_valid', 'order', "Forced address VALID on order #{$order_id}");


            return new WP_REST_Response(['success' => true, 'message' => 'Address status forced to VALID.']);
        }

        global $wex_plugin;

        if (!isset($wex_plugin)) {

            return new WP_REST_Response(['error' => 'Main_Plugin instance not available.'], 500);
        }


        $result = $wex_plugin->validate_order_address($order_id);



        if ($result->is_valid === 'true') {
            PFMP_Utils::log_admin_action('address_validated', 'order', "Address validated on order #{$order_id}");

            return new WP_REST_Response(['success' => true, 'message' => $result->message]);
        }


        return new WP_REST_Response(['success' => false, 'message' => $result->message]);
    }



    // Refund functionality moved to PFMP_REST_Refunds (includes/class-pfmp-rest-refunds.php).




    public function get_orders_by_user_id(WP_REST_Request $request) {
        global $wpdb;

        // -------- Validate --------
        $user_id = absint($request['user_id']);
        if (!$user_id) {
            return new WP_Error('invalid_user', 'Invalid user ID.', ['status' => 400]);
        }
        $per_page = max(1, absint($request->get_param('per_page') ?: 10));
        $page     = max(1, absint($request->get_param('page') ?: 1));
        $offset   = ($page - 1) * $per_page;

        // -------- Helpers --------
        $aggregate_items = function (array $rows) {
            // rows: [{ name, qty }]
            $map = [];
            foreach ($rows as $r) {
                $name = trim((string)($r['name'] ?? ''));
                $qty  = (int)($r['qty'] ?? 0);
                if ($name === '' || $qty <= 0) continue;
                $map[$name] = ['name' => $name, 'qty' => ($map[$name]['qty'] ?? 0) + $qty];
            }
            return array_values($map);
        };

        $resolve_product_name = function (int $product_id) {
            if ($product_id <= 0) return '';
            $p = wc_get_product($product_id);
            return $p ? (string)$p->get_name() : '';
        };

        // Normalize ANY item shape (WC, WRO objects, or arrays) -> { name, qty }
        $normalize_line_item = function ($it) use ($resolve_product_name) {
            $name = '';
            $qty  = 0;
            if (is_object($it)) {
                if (method_exists($it, 'get_name'))     $name = (string)$it->get_name();
                if (method_exists($it, 'get_quantity')) $qty  = (int)$it->get_quantity();

                if ($name === '') {
                    $pid = 0;
                    if (method_exists($it, 'get_variation_id')) $pid = (int)$it->get_variation_id();
                    if (!$pid && method_exists($it, 'get_product_id')) $pid = (int)$it->get_product_id();
                    if ($pid) $name = $resolve_product_name($pid);
                }

                if ($qty <= 0 && isset($it->quantity)) $qty = (int)$it->quantity;
                if ($qty <= 0 && isset($it->qty))      $qty = (int)$it->qty;
            } else {
                // Array payloads
                $name = (string)($it['name'] ?? $it['product_name'] ?? $it['title'] ?? '');
                $qty  = (int)   ($it['quantity'] ?? $it['qty'] ?? 0);
                if ($name === '') {
                    $pid = (int)($it['variation_id'] ?? 0);
                    if (!$pid) $pid = (int)($it['product_id'] ?? 0);
                    if ($pid) $name = $resolve_product_name($pid);
                }
            }

            if ($qty <= 0) $qty = 1;
            return ['name' => $name, 'qty' => $qty];
        };

        $normalize_items = function ($items) use ($normalize_line_item, $aggregate_items) {
            $rows = [];
            if (is_iterable($items)) {
                foreach ($items as $it) $rows[] = $normalize_line_item($it);
            }
            return $aggregate_items($rows);
        };

        $safe_date = fn($dt) => $dt ? date('Y-m-d H:i:s', strtotime((string)$dt)) : null;

        // -------- 1) Live Woo orders --------
        $live_orders = [];
        try {
            $orders = wc_get_orders([
                'customer_id' => $user_id,
                'limit'       => $per_page,
                'paged'       => $page,
                'orderby'     => 'date',
                'order'       => 'DESC',
                'status'      => array_keys(wc_get_order_statuses()),
            ]);

            foreach ($orders as $order) {
                /** @var WC_Order $order */
                $live_orders[] = [
                    'id'                        => $order->get_id(),
                    'status'                    => $order->get_status(),
                    'total'                     => (float)$order->get_total(),
                    'currency'                  => $order->get_currency(),
                    'date_created'              => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : null,
                    'is_archived'               => false,
                    'is_replacement'            => false,
                    'has_chargeback'            => ((float)$order->get_meta('disputed_amount')) > 0,
                    'source'                    => 'woo',
                    'has_subscription_parent'   => (bool)$order->get_meta('_subscription_parent'),
                    'has_subscription_renewal'  => (bool)$order->get_meta('_subscription_renewal'),
                    'items'                     => (function () use ($order, $normalize_items) {
                        $line_items = $order->get_items('line_item');
                        return $normalize_items($line_items);
                    })(),
                ];
            }
        } catch (\Throwable $e) {
        }

        // -------- 2) Archived Woo orders (no items) --------
        $archived_orders = [];
        try {
            $sql = $wpdb->prepare(
                "SELECT
                p.ID,
                p.post_status,
                p.post_date_gmt,
                MAX(CASE WHEN pm.meta_key = '_order_total' THEN pm.meta_value END) AS order_total,
                MAX(CASE WHEN pm.meta_key = '_order_currency' THEN pm.meta_value END) AS order_currency,
                MAX(CASE WHEN pm.meta_key = '_subscription_parent'  THEN pm.meta_value END) AS subscription_parent,
                MAX(CASE WHEN pm.meta_key = '_subscription_renewal' THEN pm.meta_value END) AS subscription_renewal
             FROM yom_archive_orders_posts p
             JOIN yom_archive_orders_postmeta m  ON p.ID = m.post_id
             LEFT JOIN yom_archive_orders_postmeta pm ON p.ID = pm.post_id
             WHERE p.post_type = 'shop_order'
               AND p.post_status NOT IN ('trash', 'auto-draft')
               AND m.meta_key = '_customer_user'
               AND m.meta_value = %s
             GROUP BY p.ID
             ORDER BY p.post_date_gmt DESC
             LIMIT %d OFFSET %d",
                $user_id,
                $per_page,
                $offset
            );

            $rows = $wpdb->get_results($sql);
            foreach ($rows as $r) {
                $archived_orders[] = [
                    'id'                        => (int)$r->ID,
                    'status'                    => (string)$r->post_status,
                    'total'                     => isset($r->order_total) ? (float)$r->order_total : 0.0,
                    'currency'                  => isset($r->order_currency) ? (string)$r->order_currency : get_woocommerce_currency(),
                    'date_created'              => $safe_date($r->post_date_gmt),
                    'is_archived'               => true,
                    'is_replacement'            => false,
                    'has_chargeback'            => false,
                    'source'                    => 'archived',
                    'has_subscription_parent'   => !empty($r->subscription_parent),
                    'has_subscription_renewal'  => !empty($r->subscription_renewal),
                    'items'                     => null, // no archive items available
                ];
            }
        } catch (\Throwable $e) {
        }

        // -------- 3) Replacement orders (normalized like Woo) --------
        $replacement_orders = [];
        try {
            if (function_exists('wro_get_orders')) {
                $rep_result = wro_get_orders([
                    'customer_id' => $user_id,
                    'limit'       => $per_page,
                    'offset'      => $offset,
                    'orderby'     => 'date_created',
                    'order'       => 'DESC',
                    'paginate'    => true,
                ]);

                foreach (($rep_result['orders'] ?? []) as $rep) {
                    $is_obj   = is_object($rep);
                    $rep_id   = $is_obj ? (int)$rep->id            : (int)($rep['id'] ?? 0);
                    $created  = $is_obj ? ($rep->created_at ?? '') : ($rep['created_at'] ?? '');
                    $status   = $is_obj ? ($rep->status ?? '')     : ($rep['status'] ?? '');
                    $itemsRaw = $is_obj ? ($rep->items ?? [])      : ($rep['items'] ?? []);

                    // items normalized exactly like Woo
                    $items = $normalize_items($itemsRaw);

                    // total: prefer item->get_total() / ['total']; fallback to product price * qty
                    $total = 0.0;
                    if (is_iterable($itemsRaw)) {
                        foreach ($itemsRaw as $it) {
                            if (is_object($it) && method_exists($it, 'get_total')) {
                                $total += (float)$it->get_total();
                                continue;
                            }
                            if (is_array($it) && isset($it['total'])) {
                                $total += (float)$it['total'];
                                continue;
                            }
                            // fallback price * qty
                            $qty = 1;
                            if (is_object($it) && method_exists($it, 'get_quantity')) {
                                $qty = max(1, (int)$it->get_quantity());
                            } elseif (is_array($it)) {
                                $qty = max(1, (int)($it['quantity'] ?? $it['qty'] ?? 1));
                            }
                            $pid = 0;
                            if (is_object($it)) {
                                if (method_exists($it, 'get_variation_id')) $pid = (int)$it->get_variation_id();
                                if (!$pid && method_exists($it, 'get_product_id')) $pid = (int)$it->get_product_id();
                            } else {
                                $pid = (int)($it['variation_id'] ?? $it['product_id'] ?? 0);
                            }
                            if ($pid) {
                                $p = wc_get_product($pid);
                                if ($p) $total += (float)$p->get_price() * $qty;
                            }
                        }
                    }

                    $replacement_orders[] = [
                        'id'                        => $rep_id,
                        'status'                    => $status,
                        'total'                     => $total,
                        'currency'                  => get_woocommerce_currency(),
                        'date_created'              => $safe_date($created),
                        'is_archived'               => false,
                        'is_replacement'            => true,
                        'has_chargeback'            => false,
                        'source'                    => 'replacement',
                        'has_subscription_parent'   => false,
                        'has_subscription_renewal'  => false,
                        'items'                     => $items, // ✅ same shape as Woo
                    ];
                }
            }
        } catch (\Throwable $e) {
        }

        // -------- 4) Merge, sort, dedupe, slice --------
        $all = array_merge($live_orders, $archived_orders, $replacement_orders);

        usort($all, function ($a, $b) {
            $ad = strtotime($a['date_created'] ?? '1970-01-01');
            $bd = strtotime($b['date_created'] ?? '1970-01-01');
            return $bd <=> $ad;
        });

        $seen = [];
        $dedup = [];
        foreach ($all as $o) {
            $k = ($o['source'] ?? 'woo') . ':' . $o['id'];
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $dedup[]  = $o;
        }

        return rest_ensure_response(array_slice($dedup, 0, $per_page));
    }





    public function update_order(WP_REST_Request $request) {
        $order_id = absint($request['id']);
        $order = wc_get_order($order_id);
        $current_user = wp_get_current_user();
        $admin_name = $current_user->display_name ?? 'Admin';
        $admin_roles = $current_user->roles ?? [];
        if (!$order) {
            return new WP_Error('not_found', 'Order not found', ['status' => 404]);
        }

        $params = $request->get_json_params();
        $updated_fields = [];

        if (isset($params['status'])) {
            $order->set_status(sanitize_key($params['status']));
            $order->add_order_note("Order status changed to '{$params['status']}' by $admin_name");
        }

        // Update meta
        if (isset($params['meta']) && is_array($params['meta'])) {
            foreach ($params['meta'] as $key => $value) {
                $order->update_meta_data(sanitize_text_field($key), sanitize_text_field($value));
                $updated_fields[] = "meta_{$key}";
            }
        }

        // Billing updates
        if (isset($params['billing']) && is_array($params['billing'])) {
            foreach ($params['billing'] as $key => $value) {
                $setter = "set_billing_" . $key;
                if (method_exists($order, $setter)) {
                    $order->$setter(sanitize_text_field($value));
                    $updated_fields[] = "billing_{$key}";
                }
            }
        }

        // Shipping updates
        if (isset($params['shipping']) && is_array($params['shipping'])) {
            foreach ($params['shipping'] as $key => $value) {
                $setter = "set_shipping_" . $key;
                if (method_exists($order, $setter)) {
                    $order->$setter(sanitize_text_field($value));
                    $updated_fields[] = "shipping_{$key}";
                }
            }
        }

        $updated_profile = false;

        // Update customer profile if requested
        if (!empty($params['update_customer_profile']) && $order->get_user_id()) {
            $user_id = $order->get_user_id();

            foreach ($params['billing'] as $key => $value) {
                update_user_meta($user_id, "billing_{$key}", sanitize_text_field($value));
            }

            foreach ($params['shipping'] as $key => $value) {
                update_user_meta($user_id, "shipping_{$key}", sanitize_text_field($value));
            }

            $updated_profile = true;
        }

        if (isset($params['customer_id']) && is_numeric($params['customer_id'])) {
            $new_customer_id = absint($params['customer_id']);
            if ($new_customer_id !== $order->get_customer_id()) {
                $order->set_customer_id($new_customer_id);
                $order->add_order_note("Customer changed to user #{$new_customer_id} by $admin_name.");
            }
        }

        try {
            $order->save();

            // Add order note summarizing updates
            if (!empty($updated_fields)) {
                $note = "<b>Customer info updated by $admin_name</b><br><br>";
                $has_address_updates = array_filter($updated_fields, function ($field) {
                    return strpos($field, 'billing_') === 0 || strpos($field, 'shipping_') === 0;
                });
                if (!empty($has_address_updates)) {
                    $note .= '🔄 <b>Billing and Shipping details were updated.</b><br><br>';
                    $note .= '✅ <b>Changes applied to:</b> ' . ($updated_profile ? 'Order and Customer Profile' : 'Order only');
                }
                $meta_updates = array_filter($updated_fields, function ($field) {
                    return strpos($field, 'meta_') === 0;
                });
                if (!empty($meta_updates)) {
                    $note .= '<br>📝 <b>Meta updated:</b> ' . implode(', ', $meta_updates);
                }

                $order->add_order_note($note);
                $order->save();

                PFMP_Utils::log_admin_action(
                    'update',
                    'order',
                    "Updated order #{$order_id}" . (!empty($updated_fields) ? ' (fields: ' . implode(', ', $updated_fields) . ')' : '')
                );
            }

            return rest_ensure_response(['success' => true, 'order_id' => $order_id]);
        } catch (Throwable $e) {
            return new WP_Error('update_failed', $e->getMessage(), ['status' => 500]);
        }
    }


    public function get_single_order(WP_REST_Request $request) {
        $order_id = absint($request['id']);
        $order = wc_get_order($order_id);
        $is_archived = $request->get_param('is_archived');

        if ($is_archived) {
            global $wpdb;
            // Fetch from archive tables (example for "General Info" only)
            $sql = $wpdb->prepare(
                "SELECT
                p.ID,
                p.post_status,
                p.post_date_gmt,
                MAX(CASE WHEN pm.meta_key = '_order_total' THEN pm.meta_value END) AS order_total,
                MAX(CASE WHEN pm.meta_key = '_order_currency' THEN pm.meta_value END) AS order_currency,
                MAX(CASE WHEN pm.meta_key = '_customer_user' THEN pm.meta_value END) AS customer_id
            FROM yom_archive_orders_posts p
            LEFT JOIN yom_archive_orders_postmeta pm ON p.ID = pm.post_id
            WHERE p.ID = %d
            GROUP BY p.ID",
                $order_id
            );
            $row = $wpdb->get_row($sql);
            if (!$row) {
                return new WP_Error('not_found', 'Order not found', ['status' => 404]);
            }


            // Return a minimal "General Info" order structure
            $data = [
                'id' => intval($row->ID),
                'status' => $row->post_status,
                'total' => isset($row->order_total) ? floatval($row->order_total) : null,
                'currency' => isset($row->order_currency) ? $row->order_currency : '',
                'customer_id' => $row->customer_id,
                'is_archived' => true,
                // Add any other info you want to show in "General Info"
            ];

            $date = $row->post_date_gmt; // or whatever your field is
            $data['date_created'] = [
                'date' => $date ? (strpos($date, '.') ? $date : $date . '.000000') : null, // add microseconds if missing
                'timezone_type' => 1,
                'timezone' => '+00:00',
            ];

            $data['billing'] = [
                'first_name' => '',
                'last_name'  => '',
                'email'      => ''
            ];
            $customer_id = isset($row->customer_id) ? intval($row->customer_id) : 0;
            if ($customer_id) {
                $user = get_userdata($customer_id);
                if ($user) {
                    $data['billing']['first_name'] = get_user_meta($customer_id, 'billing_first_name', true) ?: ($user->first_name ?? '');
                    $data['billing']['last_name']  = get_user_meta($customer_id, 'billing_last_name', true) ?: ($user->last_name ?? '');
                    $data['billing']['email']      = $user->user_email;

                    $data['customer_profile'] = [
                        'first_name' => $data['billing']['first_name'],
                        'last_name'  => $data['billing']['last_name'],
                        'email'      => $data['billing']['email'],
                    ];
                }
            }
            return rest_ensure_response($data);
        }

        if (!$order) {
            return new WP_Error('not_found', 'Order not found', ['status' => 404]);
        }

        $data = $order->get_data();
        $data['meta_data'] = $order->get_meta_data();

        // 🛒 Line Items (Products)
        $data['line_items'] = array_values(array_map(function ($item) use ($order) {
            return $this->format_item($item, $order);
        }, array_filter($order->get_items('line_item'), function ($item) {
            return is_a($item, 'WC_Order_Item_Product');
        })));

        // 💸 Fee Items with refund data
        $data['fee_lines'] = array_values(array_map(function ($item) use ($order) {
            return $this->format_item($item, $order);
        }, $order->get_items('fee')));

        // 🚚 Shipping Items with refund data
        $data['shipping_lines'] = array_values(array_map(function ($item) use ($order) {
            return $this->format_item($item, $order);
        }, $order->get_items('shipping')));

        // 🧾 Tax and Coupons (no refund data needed)
        $data['tax_lines'] = array_values(array_map([$this, 'format_item'], $order->get_items('tax')));
        $data['coupon_lines'] = array_values(array_map([$this, 'format_item'], $order->get_items('coupon')));

        // ↩ Refunds Summary
        $data['refunds'] = array_map(function ($refund) {
            // Return ISO 8601 WITH timezone offset so the frontend can parse reliably.
            // Example: 2026-01-06T13:55:54-05:00
            $date = null;
            if ($refund->get_date_created()) {
                $dt = clone $refund->get_date_created();
                $dt->modify('+5 hours');
                $date = $dt->date('c');
            }
            return [
                'id'     => $refund->get_id(),
                'total'  => $refund->get_amount(),
                'date'   => $date,
                'reason' => $refund->get_reason(),
            ];
        }, $order->get_refunds());

        $customer_id = $order->get_customer_id();
        if ($customer_id) {
            $user = get_userdata($customer_id);
            if ($user) {
                $data['customer_profile']['first_name'] = get_user_meta($customer_id, 'billing_first_name', true) ?: ($user->first_name ?? '');
                $data['customer_profile']['last_name']  = get_user_meta($customer_id, 'billing_last_name', true) ?: ($user->last_name ?? '');
                $data['customer_profile']['email']      = $user->user_email;
            }
        }

        $data['transaction_id'] = $order->get_transaction_id() ?? '';
        $data['subscription_branch'] = $this->get_subscription_branch_for_order($order);

        return rest_ensure_response($data);
    }


    private function get_subscription_branch_for_order($order) {
        $order_id = $order->get_id();
        $subscription_id = $order->get_meta('_subscription_parent') ?: $order->get_meta('_subscription_renewal');
        $branch = [];

        if (!$subscription_id) return $branch;

        // Load Subscription
        $subscription = wcs_get_subscription($subscription_id);
        if ($subscription) {
            $branch[] = [
                'id' => $subscription->get_id(),
                'relationship' => 'Subscription',
                'date' => $subscription->get_date_created()->date('Y-m-d H:i:s'),
                'status' => $subscription->get_status(),
                'total' => $subscription->get_formatted_order_total(),
            ];
        }

        // Parent orders
        $parents = wc_get_orders([
            'meta_key' => '_subscription_parent',
            'meta_value' => $subscription_id,
            'limit' => -1,
        ]);
        foreach ($parents as $parent) {
            $branch[] = [
                'id' => $parent->get_id(),
                'relationship' => 'Parent Order',
                'date' => $parent->get_date_created()->date('Y-m-d H:i:s'),
                'status' => $parent->get_status(),
                'total' => wc_price($parent->get_total(), ['currency' => $parent->get_currency()]),
            ];
        }

        // Renewals
        $renewals = wc_get_orders([
            'meta_key' => '_subscription_renewal',
            'meta_value' => $subscription_id,
            'orderby' => 'date',
            'order' => 'ASC',
            'limit' => -1,
        ]);
        foreach ($renewals as $renewal) {
            $branch[] = [
                'id' => $renewal->get_id(),
                'relationship' => 'Renewal Order',
                'date' => $renewal->get_date_created()->date('Y-m-d H:i:s'),
                'status' => $renewal->get_status(),
                'total' => wc_price($renewal->get_total(), ['currency' => $renewal->get_currency()]),
            ];
        }

        // Mark current order
        foreach ($branch as &$entry) {
            if ((int)$entry['id'] === (int)$order_id) {
                $entry['relationship'] .= ' (This Order)';
            }
        }

        return $branch;
    }


    private function format_item($item, $order = null) {
        $data = $item->get_data();

        if ($item instanceof WC_Order_Item_Product) {
            $product = $item->get_product();
            if ($product) {
                $data['sku'] = $product->get_sku();
                $data['image'] = [
                    'src' => wp_get_attachment_url($product->get_image_id()),
                    'alt' => $product->get_name(),
                ];
            }

            // Qty refunded only makes sense for products
            if ($order && method_exists($order, 'get_qty_refunded_for_item')) {
                $data['qty_refunded'] = -1 * $order->get_qty_refunded_for_item($item->get_id());
            }
        }

        $data['total_refunded'] = 0;
        $data['refunded_tax'] = 0;

        if ($order) {
            // 🧾 Sum all refunded taxes for this item across all rate IDs
            try {
                $taxes = $item->get_taxes()['total'] ?? [];

                foreach ($taxes as $rate_id => $original_tax) {
                    $refunded = $order->get_tax_refunded_for_item($item->get_id(), $rate_id, $item->get_type());
                    $data['refunded_tax'] += abs(floatval($refunded));
                }
            } catch (Throwable $e) {
                $data['refunded_tax'] = 0;
            }

            // ✅ Sum manually refunded item totals
            $refunds = $order->get_refunds();
            foreach ($refunds as $refund) {
                foreach ($refund->get_items($item->get_type()) as $refunded_item) {
                    $refunded_item_id = $refunded_item->get_meta('_refunded_item_id', true);
                    if ((int)$refunded_item_id === $item->get_id()) {
                        $data['total_refunded'] += abs($refunded_item->get_total());
                    }
                }
            }
        }

        return $data;
    }






    public function get_filtered_orders(WP_REST_Request $request) {
        // Build normal args for non-ID search
        $args = [
            'type'     => 'shop_order',
            'orderby'  => 'date',
            'order'    => 'DESC',
            'paginate' => true,
            'page'     => max(1, $request['page']),
            'limit'    => min(100, max(1, $request['per_page'])),
        ];

        // Date filters
        $date_from = $request['date_from'] ?? null;
        $date_to = $request['date_to'] ?? null;

        if ($date_from || $date_to) {
            $from_timestamp = null;
            $to_timestamp = null;

            if ($date_from) {
                $from_string = date('Y-m-d H:i:s', strtotime('-2 hours', strtotime($date_from . ' 00:00:00')));
                $from_timestamp = strtotime($from_string);
            }

            if ($date_to) {
                // Add +1 day to include full date, and +5h for offset
                $to_string = date('Y-m-d H:i:s', strtotime('+1 day -2 hours', strtotime($date_to . ' 00:00:00')));
                $to_timestamp = strtotime($to_string);
            }

            if ($from_timestamp && $to_timestamp) {
                $args['date_created'] = "{$from_timestamp}...{$to_timestamp}";
            } elseif ($from_timestamp) {
                $args['date_created'] = ">={$from_timestamp}";
            } elseif ($to_timestamp) {
                $args['date_created'] = "<={$to_timestamp}";
            }
        }

        // Status filter
        if (!empty($request['status'])) {
            $args['status'] = sanitize_key($request['status']);
        }

        // Meta filters
        $meta_query = [];

        // Warehouse
        if (isset($request['warehouse'])) {
            $wh = $request['warehouse'];
            if ($wh === 'shipstation') {
                $meta_query[] = [
                    'relation' => 'OR',
                    ['key' => 'warehouse_to_export', 'compare' => 'NOT EXISTS'],
                    ['key' => 'warehouse_to_export', 'value' => '', 'compare' => '='],
                ];
            } else {
                $meta_query[] = [
                    'key'     => 'warehouse_to_export',
                    'value'   => $wh,
                    'compare' => '=',
                ];
            }
        }

        // Export Status
        if (!empty($request['export_status'])) {
            $meta_query[] = [
                'key'   => 'warehouse_export_status',
                'value' => $request['export_status'],
            ];
        }

        // Address Validation Status
        if (!empty($request['addr_status'])) {
            $meta_query[] = [
                'key'   => 'validate_address_status',
                'value' => $request['addr_status'],
            ];
        }

        // Tag filters
        if (!empty($request['tag'])) {
            $tag = $request['tag'];
            if ($tag === 'ppu-on-hold') {
                $meta_query[] = [
                    'key' => 'ppu_status',
                    'value' => 'on-hold',
                ];
            } elseif ($tag === 'ppu-added') {
                $meta_query[] = [
                    'key' => 'ppu_products_count',
                    'value' => 0,
                    'compare' => '>',
                    'type' => 'NUMERIC',
                ];
            } elseif ($tag === 'facebook') {
                $meta_query[] = [
                    'key' => 'facebook_order_id',
                    'compare' => 'EXISTS',
                ];
            } elseif ($tag === 'walmart') {
                $meta_query[] = [
                    'key' => 'walmart_order_id',
                    'compare' => 'EXISTS',
                ];
            } elseif ($tag === 'subscription-renewal') {
                $meta_query[] = [
                    'key' => '_subscription_renewal',
                    'compare' => 'EXISTS',
                ];
            } elseif ($tag === 'subscription-parent') {
                $meta_query[] = [
                    'key' => '_subscription_parent',
                    'compare' => 'EXISTS',
                ];
            } elseif ($tag === 'bas-added') {
                $meta_query[] = [
                    'key' => '_upsell_amount',
                    'value' => 0,
                    'compare' => '>',
                    'type' => 'NUMERIC',
                ];
            } elseif ($tag === 'hotjar') {
                $meta_query[] = [
                    'relation' => 'AND',
                    [
                        'key'     => '_hotjar_last_recording_url',
                        'compare' => 'EXISTS',
                    ],
                    [
                        'key'     => '_hotjar_last_recording_url',
                        'value'   => '',
                        'compare' => '!=',
                    ],
                ];
            } elseif ($tag === 'cs-added') {
                $meta_query[] = [
                    'key'     => '_ref-cart-sidebar-total-usd',
                    'value'   => 0,
                    'compare' => '!=',
                    'type'    => 'NUMERIC',
                ];
            }
        }

        $search_type  = $request['search_type'] ?? null;
        $search_value = $request['search_value'] ?? null;


        // If searching by order_id(s), handle specially
        if ($search_type === 'order_id' && !empty($search_value)) {
            // Support multiple IDs separated by comma or space
            $id_string = str_replace([',', ';'], ' ', $search_value);
            $ids = array_filter(array_map('absint', preg_split('/\s+/', $id_string)));
            $unique_ids = array_unique($ids);

            $orders = [];
            foreach ($unique_ids as $order_id) {
                $order = wc_get_order($order_id);
                if ($order) {
                    $data = $order->get_data();
                    $data['meta_data'] = $order->get_meta_data();
                    $data['meta'] = [
                        'warehouse_to_export' => $order->get_meta('warehouse_to_export'),
                        'warehouse_export_status' => $order->get_meta('warehouse_export_status'),
                        'validate_address_status' => $order->get_meta('validate_address_status'),
                        '_wc_shipment_tracking_items' => $order->get_meta('_wc_shipment_tracking_items'),
                        '_subscription_renewal' => $order->get_meta('_subscription_renewal'),
                        '_subscription_parent' => $order->get_meta('_subscription_parent'),
                    ];
                    $orders[] = $data;
                }
            }

            $response = rest_ensure_response($orders);
            $response->header('X-WP-Total', count($orders));
            $response->header('X-WP-TotalPages', 1);

            return $response;
        }

        // Email search or normal filters
        if ($search_type === 'customer_email' && !empty($search_value)) {
            $args['billing_email'] = $search_value;
        }




        // --- Products search (HPOS, fast path) ---
        if (($request['search_type'] ?? null) === 'products' && !empty($request['search_value'])) {
            $page     = max(1, (int) $request['page']);
            $per_page = min(100, max(1, (int) $request['per_page']));

            // Parse raw "7,616257" or [7,616257]
            $selected_ids = PFMP_Query_Utils::parse_product_ids($request['search_value']);
            if (empty($selected_ids)) {
                $r = rest_ensure_response([]);
                $r->header('X-WP-Total', 0);
                $r->header('X-WP-TotalPages', 0);
                return $r;
            }

            // Expand each selected product into its group of translated IDs
            $product_groups = PFMP_Query_Utils::expand_product_groups_wpml($selected_ids);
            try {
                // 1️⃣ SQL: Get paginated order IDs that contain all selected product groups
                $slice = PFMP_Query_Utils::get_order_ids_by_product_groups_paginated(
                    $product_groups,
                    $page,
                    $per_page
                );

                // 2️⃣ Load orders for those IDs & build payload
                $payload = PFMP_Query_Utils::load_orders_payload($slice['ids']);

                // 3️⃣ Build REST response
                $resp = rest_ensure_response($payload);
                $resp->header('X-WP-Total', (int) $slice['total']);
                $resp->header(
                    'X-WP-TotalPages',
                    (int) ceil(((int) $slice['total']) / $per_page)
                );

                return $resp;
            } catch (Throwable $e) {
                return new WP_Error(
                    'order_query_failed',
                    'Failed to fetch orders.',
                    ['status' => 500]
                );
            }
        }




        if (!empty($meta_query)) {
            $args['meta_query'] = $meta_query;
        }


        try {
            $query = wc_get_orders($args);
            $order_ids = array_map(function ($order) {
                return $order->get_id();
            }, $query->orders);

            $orders = array_map(function ($order) {
                $data = $order->get_data();
                $data['meta_data'] = $order->get_meta_data();
                $data['coupon_codes'] = array_map(function ($coupon) {
                    return $coupon->get_code();
                }, $order->get_items('coupon'));
                $data['refunded_amount'] = floatval($order->get_total_refunded());
                if (($data['status'] ?? '') === 'failed') {
                    $data['failed_reason'] = $this->get_failed_reason_for_order($order->get_id());
                }
                return $data;
            }, $query->orders);

            $response = rest_ensure_response($orders);
            $response->header('X-WP-Total', $query->total);
            $response->header('X-WP-TotalPages', $query->max_num_pages);

            return $response;
        } catch (Throwable $e) {
            PFMP_Utils::log('Order query failed: ' . $e->getMessage());
            return new WP_Error('order_query_failed', 'Failed to fetch orders.', ['status' => 500]);
        }
    }

    private function get_failed_reason_for_order($order_id) {
        if (!$order_id) {
            return null;
        }

        try {
            $notes = wc_get_order_notes([
                'order_id' => $order_id,
                'type' => 'internal',
                'orderby' => 'date_created',
                'order' => 'DESC',
                'number' => 2,
            ]);
        } catch (Throwable $e) {
            return null;
        }

        if (empty($notes)) {
            return null;
        }

        $default_message = 'Order status changed from Pending payment to Failed.';

        $pick_reason = function ($note) {
            if (!$note) {
                return null;
            }
            $content = method_exists($note, 'get_content') ? $note->get_content() : ($note->content ?? '');
            $content = is_string($content) ? trim(wp_strip_all_tags($content)) : '';
            return $content !== '' ? $content : null;
        };

        $first_reason = $pick_reason($notes[0] ?? null);
        if ($first_reason && stripos($first_reason, $default_message) === false) {
            return $first_reason;
        }

        $second_reason = $pick_reason($notes[1] ?? null);
        return $second_reason ?: ($first_reason && stripos($first_reason, $default_message) === false ? $first_reason : null);
    }
}
