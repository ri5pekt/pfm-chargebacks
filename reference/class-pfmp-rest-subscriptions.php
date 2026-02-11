<?php
// class-pfmp-rest-subscriptions.php

defined('ABSPATH') || exit;

class PFMP_REST_Subscriptions {
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_rest_api']);
    }

    public function register_rest_api() {
        register_rest_route('pfm-panel/v1', '/subscriptions', [
            'methods'  => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_filtered_subscriptions'],
            'permission_callback' => ['PFMP_Utils', 'can_access_pfm_panel'],
            'args' => [
                'page'     => ['default' => 1, 'sanitize_callback' => 'absint'],
                'per_page' => ['default' => 10, 'sanitize_callback' => 'absint'],
            ],
        ]);
        register_rest_route('pfm-panel/v1', '/subscriptions/(?P<id>\d+)', [
            'methods'  => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_single_subscription'],
            'permission_callback' => ['PFMP_Utils', 'can_access_pfm_panel'],
        ]);
        register_rest_route('pfm-panel/v1', '/subscriptions/(?P<id>\d+)', [
            'methods'  => WP_REST_Server::EDITABLE, // 'POST', 'PUT', 'PATCH'
            'callback' => [$this, 'update_subscription'],
            'permission_callback' => ['PFMP_Utils', 'can_access_pfm_panel'],
        ]);
        register_rest_route('pfm-panel/v1', '/subscriptions/products', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_subscription_products'],
            'permission_callback' => ['PFMP_Utils', 'can_access_pfm_panel'],
        ]);

        register_rest_route('pfm-panel/v1', '/subscriptions/(?P<id>\d+)/edit', [
            'methods'  => 'POST',
            'callback' => [$this, 'edit_subscription_items'],
            'permission_callback' => ['PFMP_Utils', 'can_access_pfm_panel'],
        ]);

        register_rest_route('pfm-panel/v1', '/subscriptions/(?P<id>\d+)/actions', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_subscription_action'],
            'permission_callback' => ['PFMP_Utils', 'can_access_pfm_panel'],
            'args' => [
                'id' => ['required' => true],
                'action' => ['required' => true],
            ],
        ]);


        register_rest_route('pfm-panel/v1', '/subscriptions/by-user/(?P<id>\d+)', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_subscriptions_by_user'],
            'permission_callback' => ['PFMP_Utils', 'can_access_pfm_panel'],
        ]);

        register_rest_route('pfm-panel/v1', '/subscriptions/(?P<subscription_id>\d+)/notes', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_subscription_notes'],
            'permission_callback' => ['PFMP_Utils', 'can_access_pfm_panel'],
        ]);

        register_rest_route('pfm-panel/v1', '/orders/(?P<order_id>\d+)/retry-payment', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'retry_subscription_payment'],
            'permission_callback' => ['PFMP_Utils', 'can_access_pfm_panel'],
        ]);
    }

    function get_subscription_notes(WP_REST_Request $request) {
        $subscription_id = absint($request['subscription_id']);
        if (!$subscription_id) {
            return new WP_Error('invalid_subscription', 'Invalid subscription ID', ['status' => 400]);
        }
        $subscription = wcs_get_subscription($subscription_id);
        if (!$subscription) {
            return new WP_Error('not_found', 'Subscription not found', ['status' => 404]);
        }

        // WC_Subscription inherits get_customer_order_notes and get_notes from WC_Abstract_Order
        $notes = wc_get_order_notes([
            'order_id' => $subscription_id,
            'type'     => 'any',
            'orderby'  => 'date_created',
            'order'    => 'DESC',
        ]);

        $formatted = [];
        foreach ($notes as $note) {
            $formatted[] = [
                'id'           => $note->id,
                'note'         => $note->content,
                'date_created' => $note->date_created ? $note->date_created->date('c') : '',
                'author'       => $note->added_by ?: '',
                'is_customer'  => $note->customer_note ? true : false,
                'type'         => $note->customer_note ? 'customer' : 'internal',
            ];
        }

        return $formatted;
    }

    function get_subscriptions_by_user(WP_REST_Request $request) {
        $user_id = absint($request['id']);
        if (!$user_id) {
            return new WP_Error('invalid_user_id', 'Invalid user ID', ['status' => 400]);
        }
        if (!function_exists('wcs_get_users_subscriptions')) {
            return [];
        }

        $subscriptions = wcs_get_users_subscriptions($user_id);
        $result = [];
        foreach ($subscriptions as $sub) {
            $result[] = [
                'id'           => $sub->get_id(),
                'relationship' => 'Subscription',
                'date'         => $sub->get_date_created() ? $sub->get_date_created()->date('Y-m-d H:i:s') : '',
                'status'       => $sub->get_status(),
                'total'        => wc_price($sub->get_total()),
            ];
        }
        return $result;
    }

    function handle_subscription_action(WP_REST_Request $request) {
        $id     = (int) $request['id'];
        $action = $request->get_param('action');

        $subscription = wcs_get_subscription($id);
        if (!$subscription) {
            return new WP_REST_Response(['success' => false, 'message' => 'Subscription not found'], 404);
        }

        $current_user = wp_get_current_user();
        $admin_name   = $current_user && $current_user->exists() ? $current_user->display_name : 'Admin';

        try {
            switch ($action) {
                case 'process_renewal': {
                        // same as before, but with logging
                        $order = wcs_create_renewal_order($subscription);

                        PFMP_Utils::log_admin_action(
                            'action',
                            'subscription',
                            sprintf(
                                "Processed '%s' for subscription #%d by %s%s",
                                $action,
                                $id,
                                $admin_name,
                                ($order && $order->get_id()) ? " | renewal order #" . $order->get_id() : ""
                            )
                        );

                        return new WP_REST_Response([
                            'success'  => true,
                            'order_id' => $order ? $order->get_id() : null,
                        ]);
                    }

                case 'skip_next_delivery': {
                        // ⏭ Skip next payment/delivery: advance next_payment by one interval/period
                        $current_next = $subscription->get_time('next_payment');
                        if (!$current_next) {
                            return new WP_REST_Response([
                                'success' => false,
                                'message' => 'No next payment date to skip.',
                            ], 400);
                        }

                        $interval = (int) $subscription->get_billing_interval();
                        $period   = (string) $subscription->get_billing_period(); // day|week|month|year

                        $new_next_ts = wcs_add_time($interval, $period, $current_next);
                        $new_next_gm = gmdate('Y-m-d H:i:s', $new_next_ts);

                        $subscription->update_dates(['next_payment' => $new_next_gm]);
                        $subscription->add_order_note(sprintf('Next payment skipped by admin (%s). New date: %s', $admin_name, $new_next_gm));
                        $subscription->save();

                        PFMP_Utils::log_admin_action(
                            'action',
                            'subscription',
                            sprintf("Processed '%s' for subscription #%d by %s | next_payment => %s", $action, $id, $admin_name, $new_next_gm)
                        );

                        return new WP_REST_Response([
                            'success'           => true,
                            'next_payment_date' => $new_next_gm,
                        ]);
                    }

                case 'discount_next_delivery_25': {
                        // 🏷️ One-time 25% discount on the next renewal (mirrors your utils logic)
                        $already_set  = $subscription->get_meta('_discount_next_order') == '1';
                        $already_used = $subscription->get_meta('_discount_used') == '1';

                        if ($already_set || $already_used) {
                            return new WP_REST_Response([
                                'success' => false,
                                'message' => 'Discount already applied or used for this subscription.',
                            ], 400);
                        }

                        $discount_percent = 25;

                        foreach ($subscription->get_items() as $item) {
                            $original = $item->get_subtotal(); // current per-item subtotal
                            // keep the original baseline for restoring later (you already restore on renewal complete)
                            $item->add_meta_data('_original_price', $original, true);

                            $discounted = $original - ($original * ($discount_percent / 100));
                            $item->set_subtotal($discounted);
                            $item->set_total($discounted);
                            // (taxes will be recomputed via calculate_totals)
                        }

                        // flag discount state
                        $subscription->update_meta_data('_discount_next_order', '1');
                        $subscription->update_meta_data('_discount_percent', $discount_percent);
                        $subscription->update_meta_data('_discount_used', '0');

                        $subscription->add_order_note(sprintf('A %d%% discount has been applied to the next delivery by admin (%s).', $discount_percent, $admin_name));
                        $subscription->calculate_totals(); // ensure totals reflect new line totals/taxes
                        $subscription->save();

                        PFMP_Utils::log_admin_action(
                            'action',
                            'subscription',
                            sprintf("Processed '%s' for subscription #%d by %s | %d%% applied to next delivery", $action, $id, $admin_name, $discount_percent)
                        );

                        return new WP_REST_Response([
                            'success' => true,
                            'message' => '25% discount applied to next delivery.',
                        ]);
                    }

                default:
                    return new WP_REST_Response(['success' => false, 'message' => 'Unknown action'], 400);
            }
        } catch (Exception $e) {
            return new WP_REST_Response(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }



    public function edit_subscription_items(WP_REST_Request $request) {
        $id = absint($request['id']);
        $params = $request->get_json_params();

        $current_user = wp_get_current_user();
        $admin_name = $current_user->display_name ?? 'Admin';
        $admin_roles = $current_user->roles ?? [];

        if (!function_exists('wcs_get_subscription')) {
            return new WP_Error('missing_wc_subs', 'WooCommerce Subscriptions is not installed.', ['status' => 500]);
        }
        $sub = wcs_get_subscription($id);
        if (!$sub) {
            return new WP_Error('not_found', 'Subscription not found', ['status' => 404]);
        }

        $updated = false;

        // Helper for setting a single tax (first rate id)
        if (!function_exists('pfmp_set_single_tax')) {
            function pfmp_set_single_tax($item, $new_total_tax, $context = '') {
                try {
                    $taxes = $item->get_taxes();
                    $rate_ids = array_keys($taxes['total']);
                    $first_rate_id = $rate_ids ? $rate_ids[0] : null;
                    if (!$first_rate_id) {
                        $sub_taxes = $item->get_order()->get_items('tax');
                        if (!empty($sub_taxes)) {
                            $first_rate_id = (string) reset($sub_taxes)->get_rate_id();
                        }
                    }
                    if (!$first_rate_id) $first_rate_id = 1;
                    $taxes['total']    = [$first_rate_id => floatval($new_total_tax)];
                    $taxes['subtotal'] = [$first_rate_id => floatval($new_total_tax)];
                    $item->set_taxes($taxes);
                } catch (\Throwable $e) {
                    if (function_exists('PFMP_Utils::log')) {
                        PFMP_Utils::log("❌ pfmp_set_single_tax ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                    }
                }
            }
        }

        // Update existing items
        if (!empty($params['items']) && is_array($params['items'])) {
            foreach ($params['items'] as $item_data) {
                if (empty($item_data['id'])) continue;
                $item = $sub->get_item($item_data['id']);
                if (!$item || !$item->is_type('line_item')) continue;

                if (isset($item_data['quantity'])) {
                    $item->set_quantity((int)$item_data['quantity']);
                }
                if (isset($item_data['total'])) {
                    $item->set_total(floatval($item_data['total']));
                }
                if (isset($item_data['tax'])) {
                    pfmp_set_single_tax($item, $item_data['tax'], 'line_item');
                }
                $item->save();
                $updated = true;
            }
        }

        // Add new products (line items)
        if (!empty($params['new_items']) && is_array($params['new_items'])) {
            foreach ($params['new_items'] as $item_data) {
                $product_id = isset($item_data['product_id']) ? absint($item_data['product_id']) : 0;
                $quantity   = isset($item_data['quantity']) ? intval($item_data['quantity']) : 1;
                $total      = isset($item_data['total']) ? floatval($item_data['total']) : 0;
                $tax        = isset($item_data['tax']) ? floatval($item_data['tax']) : 0;

                if (!$product_id || $quantity < 1) continue;

                $sub_item_id = $sub->add_product(
                    wc_get_product($product_id),
                    $quantity,
                    [
                        'subtotal' => $total,
                        'total' => $total,
                    ]
                );

                if ($sub_item_id && $tax !== null) {
                    $item = $sub->get_item($sub_item_id);
                    if ($item) {
                        pfmp_set_single_tax($item, $tax, 'line_item');
                        $item->save();
                    }
                }
                $updated = true;
            }
        }

        // Remove items
        if (!empty($params['removed_items']) && is_array($params['removed_items'])) {
            foreach ($params['removed_items'] as $item_id) {
                $item = $sub->get_item($item_id);
                if ($item && $item->is_type('line_item')) {
                    $sub->remove_item($item_id);
                    $updated = true;
                }
            }
        }

        // Default to automatic tax calculation unless manually overridden
        $auto_tax = isset($params['auto_tax']) ? (bool)$params['auto_tax'] : true;

        // Recalculate tax totals and fix tax lines
        if ($updated) {
            $sub->add_order_note("Subscription items were edited by $admin_name.");
            if ($auto_tax) {
                /*
                if (class_exists('WC_Complyt_Tax') && method_exists('WC_Complyt_Tax', 'init') && method_exists('WC_Complyt_Tax', 'get_instance')) {
                    WC_Complyt_Tax::init();
                    $complyt = WC_Complyt_Tax::get_instance();

                    if ($complyt && method_exists($complyt, 'set_order_id')) {
                        $complyt->set_order_id($sub->get_id());
                    }
                }

                $billing = [
                    'country'  => $sub->get_billing_country(),
                    'state'    => $sub->get_billing_state(),
                    'postcode' => $sub->get_billing_postcode(),
                    'city'     => $sub->get_billing_city(),
                ];

                $sub->calculate_taxes($billing);
                $sub->calculate_totals(false);
                $sub->save();
                */
                if (class_exists('WC_Complyt_Tax') && method_exists('WC_Complyt_Tax', 'init')) WC_Complyt_Tax::init();
                apply_filters('get_rate_for_order_or_subscription', null, $sub->get_id());
            } else {
                // --- BEGIN: Remove orphaned tax lines and recalc tax totals ---
                $item_tax_totals = [];
                foreach ($sub->get_items('line_item') as $item) {
                    $taxes = $item->get_taxes()['total'] ?? [];
                    foreach ($taxes as $rate_id => $amount) {
                        if (!isset($item_tax_totals[$rate_id])) {
                            $item_tax_totals[$rate_id] = 0;
                        }
                        $item_tax_totals[$rate_id] += floatval($amount);
                    }
                }

                // Update the subscription's tax items
                foreach ($sub->get_items('tax') as $tax_item_id => $tax_item) {
                    $rate_id = $tax_item->get_rate_id();
                    $new_tax_total = isset($item_tax_totals[$rate_id]) ? $item_tax_totals[$rate_id] : 0;
                    if ($new_tax_total == 0) {
                        // Remove orphaned tax line
                        $sub->remove_item($tax_item_id);
                    } else {
                        $tax_item->set_tax_total($new_tax_total);
                        $tax_item->set_shipping_tax_total(0);
                        $tax_item->save();
                    }
                }
                // --- END: Remove orphaned tax lines and recalc tax totals ---

                // Manually set subscription total to sum of line items + taxes
                $total_tax = 0;
                foreach ($sub->get_items('tax') as $tax_item) {
                    $total_tax += floatval($tax_item->get_tax_total());
                }
                $total_products = 0;
                foreach ($sub->get_items('line_item') as $item) {
                    $total_products += floatval($item->get_total());
                }

                $sub_total = $total_products + $total_tax;
                $sub->set_total($sub_total);
                $sub->save();
            }
        }

        $cnt_updated = is_array($params['items'] ?? null) ? count(array_filter($params['items'], fn($i) => !empty($i['id']))) : 0;
        $cnt_added   = is_array($params['new_items'] ?? null) ? count($params['new_items']) : 0;
        $cnt_removed = is_array($params['removed_items'] ?? null) ? count($params['removed_items']) : 0;

        PFMP_Utils::log_admin_action(
            'edit_items',
            'subscription',
            sprintf(
                "Edited items for subscription #%d (updated:%d, added:%d, removed:%d, auto_tax:%s)",
                $id,
                $cnt_updated,
                $cnt_added,
                $cnt_removed,
                (!isset($params['auto_tax']) || $params['auto_tax']) ? 'yes' : 'no'
            )
        );

        return rest_ensure_response([
            'success' => true,
            'message' => 'Subscription updated successfully',
        ]);
    }

    public function get_subscription_products(WP_REST_Request $request) {
        $categories = get_field('categories_for_subscriptions', 'options');
        $result = [];

        if ($categories) {
            foreach ($categories as $category) {
                $cat_name = isset($category['category']) ? $category['category'] : '';
                $group = [
                    'key' => sanitize_title($cat_name),
                    'label' => $cat_name,
                    'products' => [],
                ];

                if (isset($category['products']) && is_array($category['products'])) {
                    foreach ($category['products'] as $product_line) {
                        $product_id = isset($product_line['product']) && is_object($product_line['product'])
                            ? $product_line['product']->ID
                            : (is_array($product_line['product']) && isset($product_line['product']['ID'])
                                ? $product_line['product']['ID']
                                : $product_line['product']);

                        if (!$product_id) continue;
                        $product = wc_get_product($product_id);
                        if (!$product) continue;

                        $custom_price = isset($product_line['custom_price']) && $product_line['custom_price'] !== ''
                            ? $product_line['custom_price']
                            : wc_get_price_to_display($product);

                        $group['products'][] = [
                            'id' => $product->get_id(),
                            'name' => $product->get_name(),
                            'price' => (float) $custom_price,
                            'image' => wp_get_attachment_url($product->get_image_id()),
                            'sku' => $product->get_sku(),
                        ];
                    }
                }
                $result[] = $group;
            }
        }

        return rest_ensure_response($result);
    }

    public function update_subscription(WP_REST_Request $request) {
        $id = absint($request['id']);

        if (! function_exists('wcs_get_subscription')) {
            return new WP_Error('missing_wc_subs', 'WooCommerce Subscriptions is not installed.', ['status' => 500]);
        }

        $sub = wcs_get_subscription($id);
        if (! $sub) {
            return new WP_Error('not_found', 'Subscription not found', ['status' => 404]);
        }

        $current_user = wp_get_current_user();
        $admin_name   = $current_user && ! empty($current_user->display_name) ? $current_user->display_name : 'Admin';

        $params         = $request->get_json_params();
        $updated_fields = [];

        // Are we cancelling in this call?
        $target_status = isset($params['status']) ? sanitize_key($params['status']) : null;
        $is_cancelling = ($target_status === 'cancelled');

        // -----------------------------
        // 1) UPDATE DATES & FIELDS FIRST
        // -----------------------------

        // Next payment date (ONLY if not cancelling in this same call)
        if (! $is_cancelling && isset($params['next_payment_date']) && ! empty($params['next_payment_date'])) {
            $next_payment_date_raw = $params['next_payment_date']; // Expected MySQL datetime 'Y-m-d H:i:s' in store TZ or GMT per your usage
            try {
                $sub->update_dates(['next_payment' => $next_payment_date_raw]);
                $updated_fields[] = 'next_payment_date';
            } catch (Throwable $e) {
                return new WP_Error(
                    'invalid_next_payment_date',
                    'Failed to update next payment date: ' . $e->getMessage(),
                    ['status' => 400]
                );
            }
        }

        // Billing frequency changes
        $interval_changed = false;
        $period_changed   = false;
        $old_interval     = (int) $sub->get_billing_interval();
        $old_period       = $sub->get_billing_period();

        if (isset($params['billing_interval']) && is_numeric($params['billing_interval'])) {
            $new_interval_int = (int) $params['billing_interval'];
            if ($new_interval_int !== $old_interval) {
                $sub->set_billing_interval($new_interval_int);
                $interval_changed = true;
                $updated_fields[] = 'billing_interval';
            }
        }

        if (isset($params['billing_period']) && $params['billing_period'] !== $old_period) {
            $sub->set_billing_period(sanitize_text_field($params['billing_period']));
            $period_changed = true;
            $updated_fields[] = 'billing_period';
        }

        // If frequency changed, recalc next payment (ONLY if not cancelling in this call)
        if (($interval_changed || $period_changed) && ! $is_cancelling) {
            $new_interval = (int) $sub->get_billing_interval();
            $new_period   = $sub->get_billing_period();

            // Last order created time (UTC timestamp)
            $last_order_created = $sub->get_time('last_order_date_created');
            if (! $last_order_created) {
                $last_order_created = current_time('timestamp', true); // now UTC
            }

            // Compute next payment based on new cadence
            $next_payment_ts = wcs_add_time($new_interval, $new_period, $last_order_created);

            // Never set in the past
            if ($next_payment_ts < time()) {
                $next_payment_ts = strtotime('+1 day');
            }

            $formatted_next_payment_gmt = gmdate('Y-m-d H:i:s', $next_payment_ts);

            try {
                $sub->update_dates(['next_payment' => $formatted_next_payment_gmt]);
                $updated_fields[] = 'next_payment_date (recalculated)';
            } catch (Throwable $e) {
                return new WP_Error(
                    'invalid_recalc_next_payment_date',
                    'Failed to recalc next payment date: ' . $e->getMessage(),
                    ['status' => 400]
                );
            }
        }

        // Meta data (optional)
        if (isset($params['meta']) && is_array($params['meta'])) {
            foreach ($params['meta'] as $key => $value) {
                $sub->update_meta_data(sanitize_text_field($key), is_scalar($value) ? sanitize_text_field($value) : wp_json_encode($value));
            }
            $updated_fields[] = 'meta';
        }

        // Billing fields
        if (isset($params['billing']) && is_array($params['billing'])) {
            foreach ($params['billing'] as $key => $value) {
                $setter = 'set_billing_' . $key;
                if (method_exists($sub, $setter)) {
                    $sub->$setter(is_scalar($value) ? sanitize_text_field($value) : wp_json_encode($value));
                    $updated_fields[] = 'billing_' . $key;
                }
            }
        }

        // Shipping fields
        if (isset($params['shipping']) && is_array($params['shipping'])) {
            foreach ($params['shipping'] as $key => $value) {
                $setter = 'set_shipping_' . $key;
                if (method_exists($sub, $setter)) {
                    $sub->$setter(is_scalar($value) ? sanitize_text_field($value) : wp_json_encode($value));
                    $updated_fields[] = 'shipping_' . $key;
                }
            }
        }

        // Update customer profile from billing/shipping if requested
        $updated_profile = false;
        if (! empty($params['update_customer_profile']) && $sub->get_user_id()) {
            $user_id = (int) $sub->get_user_id();
            if (isset($params['billing']) && is_array($params['billing'])) {
                foreach ($params['billing'] as $key => $value) {
                    update_user_meta($user_id, 'billing_' . sanitize_key($key), is_scalar($value) ? sanitize_text_field($value) : wp_json_encode($value));
                }
            }
            if (isset($params['shipping']) && is_array($params['shipping'])) {
                foreach ($params['shipping'] as $key => $value) {
                    update_user_meta($user_id, 'shipping_' . sanitize_key($key), is_scalar($value) ? sanitize_text_field($value) : wp_json_encode($value));
                }
            }
            $updated_profile = true;
        }

        // Propagate billing/shipping to ALL user subscriptions if requested
        if (! empty($params['update_all_subscriptions']) && $sub->get_user_id() && (isset($params['billing']) || isset($params['shipping']))) {
            $user_id        = (int) $sub->get_user_id();
            $subscriptions  = wcs_get_users_subscriptions($user_id); // array of WC_Subscription
            foreach ($subscriptions as $other_sub_id => $other_sub) {
                if ((int) $other_sub_id === (int) $sub->get_id()) {
                    continue; // current one already updated
                }
                if (isset($params['billing']) && is_array($params['billing'])) {
                    foreach ($params['billing'] as $key => $value) {
                        $setter = 'set_billing_' . $key;
                        if (method_exists($other_sub, $setter)) {
                            $other_sub->$setter(is_scalar($value) ? sanitize_text_field($value) : wp_json_encode($value));
                        }
                    }
                }
                if (isset($params['shipping']) && is_array($params['shipping'])) {
                    foreach ($params['shipping'] as $key => $value) {
                        $setter = 'set_shipping_' . $key;
                        if (method_exists($other_sub, $setter)) {
                            $other_sub->$setter(is_scalar($value) ? sanitize_text_field($value) : wp_json_encode($value));
                        }
                    }
                }
                $other_sub->save();
                $other_sub->add_order_note("Billing and/or shipping info updated via 'Update all user Subscriptions' option by {$admin_name}.");
            }
        }

        // ----------------
        // 2) STATUS LAST
        // ----------------
        if ($target_status) {

            // If cancelling, drop next_payment_date (empty or not)
            if ($is_cancelling && array_key_exists('next_payment_date', $params)) {
                unset($params['next_payment_date']);
                // optionally log a note instead of erroring:
                $sub->add_order_note('Cancellation requested; ignored next_payment_date in payload.');
            }

            $sub->update_status($target_status);
            $updated_fields[] = 'status';
        }

        // -------------
        // SAVE & NOTES
        // -------------
        try {
            $sub->save();

            if (! empty($updated_fields)) {
                $note  = "<b>🔄 Subscription updated by {$admin_name}</b><br><br>";
                $note .= '<b>Fields:</b> ' . esc_html(implode(', ', $updated_fields)) . '<br>';
                if ($updated_profile) {
                    $note .= 'Customer profile updated.<br>';
                }
                if (! empty($params['update_all_subscriptions'])) {
                    $note .= 'Changes propagated to all user subscriptions.<br>';
                }
                $sub->add_order_note($note);
                $sub->save();
            }

            if (class_exists('PFMP_Utils') && method_exists('PFMP_Utils', 'log_admin_action')) {
                PFMP_Utils::log_admin_action(
                    'update',
                    'subscription',
                    sprintf(
                        'Updated subscription #%d (fields: %s)%s%s',
                        $id,
                        $updated_fields ? implode(', ', $updated_fields) : 'none',
                        $updated_profile ? ' | updated customer profile' : '',
                        ! empty($params['update_all_subscriptions']) ? ' | propagated to all user subscriptions' : ''
                    )
                );
            }

            return rest_ensure_response(['success' => true, 'subscription_id' => $id]);
        } catch (Throwable $e) {
            return new WP_Error('update_failed', $e->getMessage(), ['status' => 500]);
        }
    }


    public function get_filtered_subscriptions(WP_REST_Request $request) {
        $page     = max(1, $request['page']);
        $per_page = min(100, max(1, $request['per_page']));

        $search_type  = $request['search_type'] ?? null;
        $search_value = $request['search_value'] ?? null;

        // 🔍 Fast path: If searching by subscription/order IDs
        if ($search_type === 'order_id' && !empty($search_value)) {
            $id_string = str_replace([',', ';'], ' ', $search_value);
            $ids = array_filter(array_map('absint', preg_split('/\s+/', $id_string)));

            $subs = array_filter(array_map('wcs_get_subscription', $ids));
            $data = [];

            foreach ($subs as $sub) {
                $customer = $sub->get_user();
                $items = [];

                foreach ($sub->get_items() as $item) {
                    $items[] = ['name' => $item->get_name()];
                }

                $data[] = [
                    'id'               => $sub->get_id(),
                    'customer_name'    => $customer ? $customer->display_name : trim($sub->get_billing_first_name() . ' ' . $sub->get_billing_last_name()),
                    'status'           => $sub->get_status(),
                    'items'            => $items,
                    'recurring_total'  => $sub->get_formatted_order_total(),
                    'billing_interval' => $sub->get_billing_interval(),
                    'billing_period'   => $sub->get_billing_period(),
                    'start_date'       => $sub->get_date_created() ? $sub->get_date_created()->date('Y-m-d') : null,
                    'next_payment_date' => $sub->get_time('next_payment') ? date('Y-m-d', $sub->get_time('next_payment')) : null,
                    'last_order_date'  => $sub->get_last_order('parent') && $sub->get_last_order('parent')->get_date_created()
                        ? $sub->get_last_order('parent')->get_date_created()->date('Y-m-d')
                        : null,
                ];
            }

            $response = rest_ensure_response($data);
            $response->header('X-WP-Total', count($data));
            $response->header('X-WP-TotalPages', 1);
            return $response;
        }

        // 🧰 Default: go through wc_get_orders()
        $args = [
            'type'     => 'shop_subscription',
            'status'   => 'any',
            'paginate' => true,
            'page'     => $page,
            'limit'    => $per_page,
            'orderby'  => 'date',
            'order'    => 'DESC',
        ];

        // 🗓️ Date filters using timestamps
        $date_from = $request['date_from'] ?? null;
        $date_to   = $request['date_to'] ?? null;

        $from_timestamp = $date_from ? strtotime(date('Y-m-d H:i:s', strtotime('-2 hours', strtotime($date_from . ' 00:00:00')))) : null;
        $to_timestamp   = $date_to   ? strtotime(date('Y-m-d H:i:s', strtotime('+1 day -2 hours', strtotime($date_to . ' 00:00:00')))) : null;

        if ($from_timestamp && $to_timestamp) {
            $args['date_created'] = "{$from_timestamp}...{$to_timestamp}";
        } elseif ($from_timestamp) {
            $args['date_created'] = ">={$from_timestamp}";
        } elseif ($to_timestamp) {
            $args['date_created'] = "<={$to_timestamp}";
        }

        if ($search_type === 'customer_email' && $search_value) {
            $args['billing_email'] = $search_value;
        }

        try {
            $results = wc_get_orders($args);
        } catch (Throwable $e) {
            return new WP_Error('subscription_query_failed', 'Failed to fetch subscriptions.', ['status' => 500]);
        }

        $orders = $results->orders;
        $total = $results->total;
        $total_pages = (int) ceil($total / $per_page);

        $data = [];

        foreach ($orders as $order) {
            $sub = wcs_get_subscription($order->get_id());
            if (!$sub) {
                PFMP_Utils::log("⚠️ Skipped invalid sub ID: {$order->get_id()}");
                continue;
            }

            $customer = $sub->get_user();
            $items = [];

            foreach ($sub->get_items() as $item) {
                $items[] = ['name' => $item->get_name()];
            }

            $data[] = [
                'id'               => $sub->get_id(),
                'customer_name'    => $customer ? $customer->display_name : trim($sub->get_billing_first_name() . ' ' . $sub->get_billing_last_name()),
                'status'           => $sub->get_status(),
                'items'            => $items,
                'recurring_total'  => $sub->get_formatted_order_total(),
                'billing_interval' => $sub->get_billing_interval(),
                'billing_period'   => $sub->get_billing_period(),
                'start_date'       => $sub->get_date_created() ? $sub->get_date_created()->date('Y-m-d') : null,
                'next_payment_date' => $sub->get_time('next_payment') ? date('Y-m-d', $sub->get_time('next_payment')) : null,
                'last_order_date'  => $sub->get_last_order('parent') && $sub->get_last_order('parent')->get_date_created()
                    ? $sub->get_last_order('parent')->get_date_created()->date('Y-m-d')
                    : null,
            ];
        }

        $response = rest_ensure_response($data);
        $response->header('X-WP-Total', $total);
        $response->header('X-WP-TotalPages', $total_pages);

        return $response;
    }




    public function get_single_subscription(WP_REST_Request $request) {
        $id = absint($request['id']);
        if (!function_exists('wcs_get_subscription')) {
            return new WP_Error('missing_wc_subs', 'WooCommerce Subscriptions is not installed.', ['status' => 500]);
        }
        $sub = wcs_get_subscription($id);
        if (!$sub) {
            return new WP_Error('not_found', 'Subscription not found', ['status' => 404]);
        }
        $customer = $sub->get_user();

        // --- Line Items (Products) ---
        $line_items = [];
        foreach ($sub->get_items() as $item) {
            $product = $item->get_product();
            $line_items[] = [
                'id' => $item->get_id(),
                'product_id' => $item->get_product_id(),
                'name' => $item->get_name(),
                'sku' => $product ? $product->get_sku() : '',
                'quantity' => $item->get_quantity(),
                'subtotal' => wc_format_decimal($item->get_subtotal(), 2),
                'total' => wc_format_decimal($item->get_total(), 2),
                'total_tax' => wc_format_decimal($item->get_total_tax(), 2),
                'price' => wc_format_decimal($product ? $product->get_price() : 0, 2),
                'image' => $product && $product->get_image_id() ? [
                    'src' => wp_get_attachment_url($product->get_image_id())
                ] : null,
                'meta_data' => $item->get_formatted_meta_data(''),
            ];
        }

        // --- Coupon Lines ---
        $coupon_lines = [];
        foreach ($sub->get_items('coupon') as $coupon) {
            $coupon_lines[] = [
                'id' => $coupon->get_id(),
                'code' => $coupon->get_code(),
                'discount' => wc_format_decimal($coupon->get_discount(), 2),
            ];
        }

        // --- Tax Lines ---
        $tax_lines = [];
        foreach ($sub->get_items('tax') as $tax) {
            $tax_lines[] = [
                'id' => $tax->get_id(),
                'rate_id' => $tax->get_rate_id(),
                'label' => $tax->get_label(),
                'compound' => $tax->is_compound(),
                'tax_total' => wc_format_decimal($tax->get_tax_total(), 2),
                'shipping_tax_total' => wc_format_decimal($tax->get_shipping_tax_total(), 2),
            ];
        }

        // --- Totals ---
        $subtotal = 0;
        $total_tax = 0;
        foreach ($line_items as $item) {
            $subtotal += floatval($item['subtotal']);
            $total_tax += floatval($item['total_tax']);
        }
        $total = floatval($sub->get_total());

        $branch = $this->get_subscription_branch_for_subscription($sub);

        return [
            'id' => $sub->get_id(),
            'status' => $sub->get_status(),
            'date_created' => $sub->get_date_created() ? $sub->get_date_created()->date('Y-m-d H:i:s') : null,
            'start_date' => $sub->get_date_created() ? $sub->get_date_created()->date('Y-m-d H:i:s') : null,
            'next_payment_date' => $sub->get_time('next_payment') ? date('Y-m-d H:i:s', $sub->get_time('next_payment')) : null,
            'last_order_date' => $sub->get_last_order('parent') && $sub->get_last_order('parent')->get_date_created()
                ? $sub->get_last_order('parent')->get_date_created()->date('Y-m-d H:i:s')
                : null,
            'recurring_total' => $sub->get_formatted_order_total(),
            'billing_interval' => $sub->get_billing_interval(),
            'billing_period' => $sub->get_billing_period(),
            'customer' => [
                'id' => $customer ? $customer->ID : null,
                'name' => $customer ? $customer->display_name : trim($sub->get_billing_first_name() . ' ' . $sub->get_billing_last_name()),
                'email' => $sub->get_billing_email(),
                'phone' => $sub->get_billing_phone(),
            ],
            'billing' => $sub->get_address('billing'),
            'shipping' => $sub->get_address('shipping'),
            'line_items' => $line_items,
            'coupon_lines' => $coupon_lines,
            'tax_lines' => $tax_lines,
            'subtotal' => wc_format_decimal($subtotal, 2),
            'total_tax' => wc_format_decimal($total_tax, 2),
            'total' => wc_format_decimal($total, 2),
            'payment_method_title' => $sub->get_payment_method_title(),
            'branch' => $branch,
        ];
    }


    public function retry_subscription_payment(WP_REST_Request $request) {
        $order_id = absint($request['order_id']);
        if (!$order_id) {
            return new WP_Error('invalid_order', 'Invalid order ID', ['status' => 400]);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('not_found', 'Order not found', ['status' => 404]);
        }

        $current_status = $order->get_status();
        if ($current_status !== 'pending') {
            return new WP_Error('invalid_status', 'Only pending payment renewal orders can be retried.', ['status' => 400]);
        }

        $is_renewal = false;
        if (function_exists('wcs_order_contains_subscription')) {
            $is_renewal = wcs_order_contains_subscription($order, ['order_type' => 'renewal']);
        }
        if (!$is_renewal) {
            $is_renewal = (bool) $order->get_meta('_subscription_renewal');
        }
        if (!$is_renewal) {
            return new WP_Error('not_subscription_renewal', 'Order is not a subscription renewal.', ['status' => 400]);
        }

        $current_user = wp_get_current_user();
        $admin_name   = $current_user->display_name ?: 'Admin';

        try {
            /**
             * WooCommerce Subscriptions registers this action to kick off the retry flow.
             * See https://woocommerce.com/document/subscriptions/develop/failed-payment-retry/
             */
            do_action('woocommerce_order_action_wcs_retry_renewal_payment', $order);
        } catch (Throwable $e) {
            return new WP_Error('retry_failed', $e->getMessage(), ['status' => 500]);
        }

        $order->add_order_note(sprintf('🔁 Payment retry triggered via PFM Panel by %s.', $admin_name));
        $order->save();

        if (class_exists('PFMP_Utils') && method_exists('PFMP_Utils', 'log_admin_action')) {
            PFMP_Utils::log_admin_action('retry_payment', 'order', "Payment retry triggered for order #{$order_id}");
        }

        $order = wc_get_order($order_id);
        $status_slug  = $order ? $order->get_status() : $current_status;
        $status_label = function_exists('wc_get_order_status_name') ? wc_get_order_status_name("wc-{$status_slug}") : $status_slug;

        return rest_ensure_response([
            'success'       => true,
            'message'       => 'Payment retry triggered.',
            'order_id'      => $order_id,
            'order_status'  => $status_slug,
            'status_label'  => $status_label,
        ]);
    }

    private function get_subscription_branch_for_subscription($sub) {
        $subscription_id = $sub->get_id();
        $branch = [];

        // Main Subscription
        $branch[] = [
            'id' => $sub->get_id(),
            'relationship' => 'Subscription',
            'date' => $sub->get_date_created() ? $sub->get_date_created()->date('Y-m-d H:i:s') : null,
            'status' => $sub->get_status(),
            'total' => $sub->get_formatted_order_total(),
        ];

        // Parent Orders
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

        // Mark "This Subscription"
        foreach ($branch as &$entry) {
            if ((int)$entry['id'] === (int)$subscription_id) {
                $entry['relationship'] .= ' (This Subscription)';
            }
        }
        return $branch;
    }
}
