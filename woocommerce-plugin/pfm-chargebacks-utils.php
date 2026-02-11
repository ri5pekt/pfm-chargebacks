<?php
/**
 * Plugin Name: PFM Chargebacks Utils
 * Description: REST API endpoint for chargeback automation — returns WooCommerce order data.
 * Version: 1.0.0
 * Author: PFM
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PFM_Chargebacks_Utils {

    private const API_TOKEN = 'pfm-cb-2026-secret';
    private const NAMESPACE = 'pfm-chargebacks/v1';

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/order/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_order' ],
            'permission_callback' => [ $this, 'check_token' ],
        ] );
    }

    public function check_token( \WP_REST_Request $request ): bool {
        return $request->get_param( 'token' ) === self::API_TOKEN;
    }

    public function get_order( \WP_REST_Request $request ): \WP_REST_Response {
        $order_id = (int) $request->get_param( 'id' );
        $order    = wc_get_order( $order_id );

        if ( ! $order ) {
            return new \WP_REST_Response( [ 'error' => 'Order not found' ], 404 );
        }

        // Field list comes from GET query: ?fields=woo_order_get_id,woo_order_get_billing_email,...
        $fields_param = $request->get_param( 'fields' );
        $requested    = [];
        if ( is_string( $fields_param ) && $fields_param !== '' ) {
            $requested = array_filter( array_map( 'trim', explode( ',', $fields_param ) ) );
        }
        $data = $this->resolve_requested_fields( $order, $requested );
        return new \WP_REST_Response( $data, 200 );
    }

    /**
     * Resolve requested field keys to values.
     * - woo_order_get_<suffix> → $order->get_<suffix>() e.g. woo_order_get_id → $order->get_id()
     * - woo_order_meta_<key> → $order->get_meta('<key>')
     *
     * @param \WC_Order $order
     * @param string[]  $requested Field keys (e.g. woo_order_get_id, woo_order_meta_my_field)
     * @return array<string, string> Key => string value
     */
    private function resolve_requested_fields( $order, array $requested ): array {
        $out = [];
        foreach ( array_unique( $requested ) as $key ) {
            $key = trim( $key );
            if ( $key === '' ) {
                continue;
            }
            $val = $this->resolve_one_field( $order, $key );
            if ( $val !== null ) {
                $out[ $key ] = $val;
            }
        }
        return $out;
    }

    /**
     * @param \WC_Order $order
     * @param string    $key e.g. woo_order_get_id or woo_order_meta_custom_field or special_products_and_quantities
     * @return string|null
     */
    private function resolve_one_field( $order, string $key ): ?string {
        if ( strpos( $key, 'woo_order_get_' ) === 0 ) {
            $suffix = substr( $key, strlen( 'woo_order_get_' ) );
            if ( $suffix === '' ) {
                return null;
            }
            $method = 'get_' . $suffix;
            if ( ! is_callable( [ $order, $method ] ) ) {
                return null;
            }
            $raw = $order->$method();
            return $this->value_to_string( $raw );
        }
        if ( strpos( $key, 'woo_order_meta_' ) === 0 ) {
            $meta_key = substr( $key, strlen( 'woo_order_meta_' ) );
            if ( $meta_key === '' ) {
                return null;
            }
            
            // Support nested values with dot notation (e.g., _braintree_card_details.last4)
            $parts = explode( '.', $meta_key, 2 );
            $base_key = $parts[0];
            $nested_path = isset( $parts[1] ) ? $parts[1] : null;
            
            $raw = $order->get_meta( $base_key );
            
            // If nested path is specified, navigate to the nested value
            if ( $nested_path !== null && $raw !== null ) {
                $raw = $this->get_nested_value( $raw, $nested_path );
            }
            
            return $this->value_to_string( $raw );
        }
        if ( strpos( $key, 'special_' ) === 0 ) {
            $special_type = substr( $key, strlen( 'special_' ) );
            if ( $special_type === '' ) {
                return null;
            }
            return $this->resolve_special_field( $order, $special_type );
        }
        return null;
    }

    /**
     * @param \WC_Order $order
     * @param string    $special_type e.g. products_and_quantities, case_id, disputed_total, reference_number, tracking_number, shipping_carrier, reason_code_and_reason, subscription_first_order_date, subscription_billing_months, ppu_product_name
     * @return string|null
     */
    private function resolve_special_field( $order, string $special_type ): ?string {
        switch ( $special_type ) {
            case 'products_and_quantities':
                return $this->format_products_and_quantities( $order );
            case 'case_id':
                return $this->get_dispute_id( $order );
            case 'disputed_total':
                return $this->get_dispute_amount( $order );
            case 'reference_number':
                return $this->get_dispute_reference_number( $order );
            case 'tracking_number':
                return $this->get_tracking_number( $order );
            case 'shipping_carrier':
                return $this->get_shipping_carrier( $order );
            case 'reason_code_and_reason':
                return $this->get_dispute_reason_code_and_reason( $order );
            case 'reason_code_and_title':
                return $this->get_dispute_reason_code_and_title( $order );
            case 'dispute_reason':
                return $this->get_dispute_reason( $order );
            case 'dispute_reason_description':
                return $this->get_dispute_reason_description( $order );
            case 'subscription_first_order_date':
                return $this->get_subscription_first_order_date( $order );
            case 'subscription_billing_months':
                return $this->get_subscription_billing_months( $order );
            case 'ppu_product_name':
                return $this->get_ppu_product_name( $order );
            default:
                return null;
        }
    }

    /**
     * Format order line items as "Product Name x Quantity"
     * @param \WC_Order $order
     * @return string
     */
    private function format_products_and_quantities( $order ): string {
        $items = $order->get_items();
        if ( empty( $items ) ) {
            return '';
        }
        $lines = [];
        foreach ( $items as $item ) {
            $product_name = $item->get_name();
            $quantity     = $item->get_quantity();
            $lines[]      = $product_name . ' x ' . $quantity;
        }
        return implode( ', ', $lines );
    }

    /**
     * Get Braintree dispute ID (case ID) for the order
     * @param \WC_Order $order
     * @return string|null
     */
    private function get_dispute_id( $order ): ?string {
        $transaction_id = $order->get_transaction_id();
        if ( ! $transaction_id ) {
            return null;
        }

        try {
            $this->configure_braintree();

            $transaction = \Braintree\Transaction::find( $transaction_id );
            if ( isset( $transaction->disputes ) && is_array( $transaction->disputes ) && ! empty( $transaction->disputes ) ) {
                // Return the ID of the first dispute
                return $transaction->disputes[0]->id ?? null;
            }
        } catch ( \Exception $e ) {
            // Silently fail if Braintree is not available or transaction not found
            return null;
        }

        return null;
    }

    /**
     * Get Braintree dispute amount for the order
     * @param \WC_Order $order
     * @return string|null
     */
    private function get_dispute_amount( $order ): ?string {
        $transaction_id = $order->get_transaction_id();
        if ( ! $transaction_id ) {
            return null;
        }

        try {
            $this->configure_braintree();

            $transaction = \Braintree\Transaction::find( $transaction_id );
            if ( isset( $transaction->disputes ) && is_array( $transaction->disputes ) && ! empty( $transaction->disputes ) ) {
                // Return the amount of the first dispute
                $amount = $transaction->disputes[0]->amount ?? null;
                return $amount !== null ? (string) $amount : null;
            }
        } catch ( \Exception $e ) {
            // Silently fail if Braintree is not available or transaction not found
            return null;
        }

        return null;
    }

    /**
     * Get Braintree dispute reference number for the order
     * @param \WC_Order $order
     * @return string|null
     */
    private function get_dispute_reference_number( $order ): ?string {
        $transaction_id = $order->get_transaction_id();
        if ( ! $transaction_id ) {
            return null;
        }

        try {
            $this->configure_braintree();

            $transaction = \Braintree\Transaction::find( $transaction_id );
            if ( isset( $transaction->disputes ) && is_array( $transaction->disputes ) && ! empty( $transaction->disputes ) ) {
                $dispute = $transaction->disputes[0];
                // Return referenceNumber (the actual Reference Number field from Braintree)
                return $dispute->referenceNumber ?? null;
            }
        } catch ( \Exception $e ) {
            // Silently fail if Braintree is not available or transaction not found
            return null;
        }

        return null;
    }

    /**
     * Get tracking number from WooCommerce Shipment Tracking plugin
     * @param \WC_Order $order
     * @return string|null
     */
    private function get_tracking_number( $order ): ?string {
        $tracking_items = $order->get_meta( '_wc_shipment_tracking_items' );
        
        if ( empty( $tracking_items ) || ! is_array( $tracking_items ) ) {
            return null;
        }

        // Get tracking numbers from all tracking items
        $tracking_numbers = [];
        foreach ( $tracking_items as $item ) {
            if ( is_array( $item ) && isset( $item['tracking_number'] ) && ! empty( $item['tracking_number'] ) ) {
                $tracking_numbers[] = $item['tracking_number'];
            }
        }

        if ( empty( $tracking_numbers ) ) {
            return null;
        }

        // Return all tracking numbers comma-separated
        return implode( ', ', $tracking_numbers );
    }

    /**
     * Get shipping carrier from WooCommerce Shipment Tracking plugin
     * @param \WC_Order $order
     * @return string|null
     */
    private function get_shipping_carrier( $order ): ?string {
        // First try AfterShip provider name (simpler field)
        $aftership_provider = $order->get_meta( '_aftership_tracking_provider_name' );
        if ( ! empty( $aftership_provider ) ) {
            return $aftership_provider;
        }

        // Fallback to WooCommerce Shipment Tracking items
        $tracking_items = $order->get_meta( '_wc_shipment_tracking_items' );
        
        if ( empty( $tracking_items ) || ! is_array( $tracking_items ) ) {
            return null;
        }

        // Get carriers from all tracking items
        $carriers = [];
        foreach ( $tracking_items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $carrier = null;
            
            // If tracking_provider is empty or "Custom", use custom_tracking_provider
            if ( empty( $item['tracking_provider'] ) || strtolower( $item['tracking_provider'] ) === 'custom' ) {
                $carrier = $item['custom_tracking_provider'] ?? null;
            } else {
                // Otherwise use tracking_provider
                $carrier = $item['tracking_provider'] ?? null;
            }

            if ( ! empty( $carrier ) ) {
                $carriers[] = $carrier;
            }
        }

        if ( empty( $carriers ) ) {
            return null;
        }

        // Return all carriers comma-separated (remove duplicates)
        return implode( ', ', array_unique( $carriers ) );
    }

    /**
     * Get Braintree dispute reason code and reason (e.g., "53 - Product Unsatisfactory")
     * @param \WC_Order $order
     * @return string|null
     */
    private function get_dispute_reason_code_and_reason( $order ): ?string {
        $transaction_id = $order->get_transaction_id();
        if ( ! $transaction_id ) {
            return null;
        }

        try {
            $this->configure_braintree();

            $transaction = \Braintree\Transaction::find( $transaction_id );
            if ( isset( $transaction->disputes ) && is_array( $transaction->disputes ) && ! empty( $transaction->disputes ) ) {
                $dispute = $transaction->disputes[0];
                $reason_code = $dispute->reasonCode ?? null;
                $reason = $dispute->reason ?? null;

                if ( $reason_code && $reason ) {
                    return $reason_code . ' - ' . $reason;
                }
                return $reason_code ?? $reason ?? null;
            }
        } catch ( \Exception $e ) {
            return null;
        }

        return null;
    }

    /**
     * Get Braintree dispute reason code and title/description (e.g., "53 - Consumer Dispute")
     * @param \WC_Order $order
     * @return string|null
     */
    private function get_dispute_reason_code_and_title( $order ): ?string {
        $transaction_id = $order->get_transaction_id();
        if ( ! $transaction_id ) {
            return null;
        }

        try {
            $this->configure_braintree();

            $transaction = \Braintree\Transaction::find( $transaction_id );
            if ( isset( $transaction->disputes ) && is_array( $transaction->disputes ) && ! empty( $transaction->disputes ) ) {
                $dispute = $transaction->disputes[0];
                $reason_code = $dispute->reasonCode ?? null;
                // Try reasonDescription first, then kind, then reason
                $title = $dispute->reasonDescription ?? $dispute->kind ?? $dispute->reason ?? null;

                if ( $reason_code && $title ) {
                    return $reason_code . ' - ' . $title;
                }
                return $reason_code ?? $title ?? null;
            }
        } catch ( \Exception $e ) {
            return null;
        }

        return null;
    }

    /**
     * Get Braintree dispute reason only (e.g., "Fraud")
     * @param \WC_Order $order
     * @return string|null
     */
    private function get_dispute_reason( $order ): ?string {
        $transaction_id = $order->get_transaction_id();
        if ( ! $transaction_id ) {
            return null;
        }

        try {
            $this->configure_braintree();

            $transaction = \Braintree\Transaction::find( $transaction_id );
            if ( isset( $transaction->disputes ) && is_array( $transaction->disputes ) && ! empty( $transaction->disputes ) ) {
                $dispute = $transaction->disputes[0];
                return $dispute->reason ?? null;
            }
        } catch ( \Exception $e ) {
            return null;
        }

        return null;
    }

    /**
     * Get Braintree dispute reason description (e.g., "Fraud (Card not present)")
     * @param \WC_Order $order
     * @return string|null
     */
    private function get_dispute_reason_description( $order ): ?string {
        $transaction_id = $order->get_transaction_id();
        if ( ! $transaction_id ) {
            return null;
        }

        try {
            $this->configure_braintree();

            $transaction = \Braintree\Transaction::find( $transaction_id );
            if ( isset( $transaction->disputes ) && is_array( $transaction->disputes ) && ! empty( $transaction->disputes ) ) {
                $dispute = $transaction->disputes[0];
                return $dispute->reasonDescription ?? $dispute->reason ?? null;
            }
        } catch ( \Exception $e ) {
            return null;
        }

        return null;
    }

    /**
     * Get date of first order in subscription branch (parent order date)
     * @param \WC_Order $order
     * @return string|null
     */
    private function get_subscription_first_order_date( $order ): ?string {
        // Check if WooCommerce Subscriptions is available
        if ( ! function_exists( 'wcs_get_subscription' ) ) {
            return null;
        }

        // Check if this order is related to a subscription
        $subscription_id = $order->get_meta( '_subscription_renewal' );
        if ( ! $subscription_id ) {
            $subscription_id = $order->get_meta( '_subscription_parent' );
        }

        if ( ! $subscription_id ) {
            return null;
        }

        // Get the subscription
        $subscription = wcs_get_subscription( $subscription_id );
        if ( ! $subscription ) {
            return null;
        }

        // Get parent orders
        $parents = wc_get_orders( [
            'meta_key'   => '_subscription_parent',
            'meta_value' => $subscription_id,
            'limit'      => -1,
            'orderby'    => 'date',
            'order'      => 'ASC',
        ] );

        if ( ! empty( $parents ) ) {
            // Return the date of the first parent order
            $first_parent = $parents[0];
            $date = $first_parent->get_date_created();
            return $date ? $date->date( 'Y-m-d H:i:s' ) : null;
        }

        // If no parent orders, return subscription start date
        $date = $subscription->get_date_created();
        return $date ? $date->date( 'Y-m-d H:i:s' ) : null;
    }

    /**
     * Get subscription billing interval in months (e.g., "2" for every 2 months)
     * @param \WC_Order $order
     * @return string|null
     */
    private function get_subscription_billing_months( $order ): ?string {
        // Check if WooCommerce Subscriptions is available
        if ( ! function_exists( 'wcs_get_subscription' ) ) {
            return null;
        }

        // Check if this order is related to a subscription
        $subscription_id = $order->get_meta( '_subscription_renewal' );
        if ( ! $subscription_id ) {
            $subscription_id = $order->get_meta( '_subscription_parent' );
        }

        if ( ! $subscription_id ) {
            return null;
        }

        // Get the subscription
        $subscription = wcs_get_subscription( $subscription_id );
        if ( ! $subscription ) {
            return null;
        }

        // Get billing interval and period
        $interval = $subscription->get_billing_interval();
        $period = $subscription->get_billing_period();

        // Only return the number if the period is 'month'
        if ( strtolower( $period ) === 'month' ) {
            return (string) $interval;
        }

        return null;
    }

    /**
     * Get the name of the first post-purchase upsell (PPU) product
     * @param \WC_Order $order
     * @return string|null
     */
    private function get_ppu_product_name( $order ): ?string {
        // Iterate through line items to find PPU products
        $items = $order->get_items();
        foreach ( $items as $item ) {
            // Check for is_ppu meta with value 'yes'
            $is_ppu = $item->get_meta( 'is_ppu' );
            
            if ( $is_ppu === 'yes' ) {
                return $item->get_name();
            }
        }

        return null;
    }

    /**
     * Configure Braintree API credentials
     */
    private function configure_braintree(): void {
        $cfg = get_option( 'woocommerce_braintree_api_settings' );
        if ( ! $cfg ) {
            return;
        }

        $env = $cfg['environment'] ?? 'production';
        \Braintree\Configuration::environment( $env );
        \Braintree\Configuration::merchantId( $cfg[ $env . '_merchant_id' ] ?? '' );
        \Braintree\Configuration::publicKey( $cfg[ $env . '_public_key' ] ?? '' );
        \Braintree\Configuration::privateKey( $cfg[ $env . '_private_key' ] ?? '' );
    }

    /**
     * Get nested value from array/object using dot notation path
     * @param mixed  $data The data structure (array or object)
     * @param string $path Dot-separated path (e.g., "last4" or "address.city")
     * @return mixed The nested value or null if not found
     */
    private function get_nested_value( $data, string $path ) {
        $keys = explode( '.', $path );
        
        foreach ( $keys as $key ) {
            if ( is_array( $data ) && isset( $data[ $key ] ) ) {
                $data = $data[ $key ];
            } elseif ( is_object( $data ) && isset( $data->$key ) ) {
                $data = $data->$key;
            } else {
                return null;
            }
        }
        
        return $data;
    }

    /**
     * @param mixed $raw
     * @return string
     */
    private function value_to_string( $raw ): string {
        if ( $raw === null || $raw === '' ) {
            return '';
        }
        if ( $raw instanceof \WC_DateTime ) {
            return $raw->date( 'Y-m-d H:i:s' );
        }
        if ( is_scalar( $raw ) ) {
            $str = (string) $raw;
            // Replace <br/> and <br> tags with commas, then strip all HTML tags
            $str = preg_replace( '/<br\s*\/?>/i', ', ', $str );
            $str = strip_tags( $str );
            // Clean up multiple spaces
            $str = preg_replace( '/\s+/', ' ', $str );
            return trim( $str );
        }
        if ( is_array( $raw ) ) {
            return wp_json_encode( $raw );
        }
        if ( is_object( $raw ) ) {
            return wp_json_encode( $raw );
        }
        return (string) $raw;
    }
}

new PFM_Chargebacks_Utils();
