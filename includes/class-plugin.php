<?php
namespace WCCC;

defined('ABSPATH') || exit;

final class Plugin {

    public static function init() : void {
        // Assets
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        // Section headings
        add_action('woocommerce_before_checkout_billing_form', [__CLASS__, 'heading_contact'], 3);
        add_action('woocommerce_before_checkout_billing_form', [__CLASS__, 'heading_tickets'], 48);

        // Fields
        add_filter('woocommerce_checkout_fields', [__CLASS__, 'checkout_fields'], 9999);

        // Validation + Save
        add_action('woocommerce_checkout_process', [__CLASS__, 'validate_checkout']);
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'save_order_meta'], 10, 2);

        // Admin + Emails
        add_action('woocommerce_admin_order_data_after_billing_address', [__CLASS__, 'admin_meta_box']);
        add_filter('woocommerce_email_order_meta_fields', [__CLASS__, 'email_meta_fields'], 10, 3);

        // Fees
        add_action('woocommerce_cart_calculate_fees', [__CLASS__, 'per_ticket_surcharge'], 20, 1);
    }

    /** ========== Options ========== */
    public static function get_options() : array {
        $defaults = [
            'fee_enabled'   => 1,
            'fee_title'     => __('Pay on the Door Surcharge', 'wc-conference-checkout'),
            'fee_amount'    => 10,
            'fee_taxable'   => 0,
            'fee_tax_class' => '', // empty = standard rules (none if not taxable)
            'fee_only_cod'  => 1,  // apply only when COD selected
            'newsletter_label' => __('I agree to be added to the newsletter and receive conference updates (optional)', 'wc-conference-checkout'),
        ];
        $saved = get_option('wccc_options', []);
        return wp_parse_args(is_array($saved) ? $saved : [], $defaults);
    }

    /** ========== Helpers ========== */
    public static function cart_ticket_blocks() : array {
        $blocks = [];
        $ticket_n = 0;
        if ( ! function_exists('WC') || ! \WC()->cart ) return $blocks;

        foreach ( \WC()->cart->get_cart() as $item_key => $item ) {
            $qty = isset($item['quantity']) ? (int) $item['quantity'] : 0;
            if ( $qty < 1 ) continue;

            $product  = isset($item['data']) ? $item['data'] : null;
            $name     = $product ? $product->get_name() : esc_html__('Product', 'wc-conference-checkout');
            $sku      = $product ? $product->get_sku()  : '';
            $sku_txt  = $sku ? " [{$sku}]" : '';

            for ($i = 1; $i <= $qty; $i++) {
                $ticket_n++;
                $key   = sanitize_key('t_' . substr($item_key, 0, 12) . '_' . $i);
                $label = sprintf(
                    esc_html__('Ticket %1$d — %2$s%3$s (%4$d of %5$d)', 'wc-conference-checkout'),
                    $ticket_n, $name, $sku_txt, $i, $qty
                );
                $blocks[] = ['key' => $key, 'label' => $label];
            }
        }
        return $blocks;
    }

    public static function ticket_count() : int {
        return count(self::cart_ticket_blocks());
    }

    private static function ends_with(string $haystack, string $needle) : bool {
        if ($needle === '') return true;
        $len = strlen($needle);
        return substr($haystack, -$len) === $needle;
    }

    /** ========== Assets ========== */
    public static function enqueue_assets() : void {
        if ( function_exists('is_checkout') && is_checkout() ) {
            wp_enqueue_style('wccc-checkout', WCCC_URL . 'assets/css/checkout.css', [], WCCC_VERSION);
            wp_enqueue_script('wccc-checkout', WCCC_URL . 'assets/js/checkout.js', ['jquery', 'wc-checkout'], WCCC_VERSION, true);
        }
    }

    /** ========== Headings ========== */
    public static function heading_contact() : void {
        echo '<div class="wccc-section wccc-section--contact"><h3>' .
            esc_html__('Contact Information', 'wc-conference-checkout') . '</h3></div>';
    }
    public static function heading_tickets() : void {
        echo '<div class="wccc-section wccc-section--tickets"><h3>' .
            esc_html__('Ticket Information', 'wc-conference-checkout') . '</h3></div>';
    }

    /** ========== Fields ========== */
    public static function checkout_fields(array $fields) : array {
        $opts = self::get_options();

        // Keep only a subset from billing
        $keep = ['billing_first_name','billing_last_name','billing_country','billing_address_1'];
        foreach ($fields['billing'] as $k => $def) {
            if ( ! in_array($k, $keep, true) ) unset($fields['billing'][$k]);
        }

        // Contact subset
        $fields['billing']['billing_first_name'] = [
            'label'    => esc_html__('First name', 'wc-conference-checkout'),
            'required' => true,
            'class'    => ['form-row-first'],
            'priority' => 10,
        ];
        $fields['billing']['billing_last_name'] = [
            'label'    => esc_html__('Last name', 'wc-conference-checkout'),
            'required' => true,
            'class'    => ['form-row-last'],
            'priority' => 20,
        ];
        $fields['billing']['billing_country'] = [
            'label'    => esc_html__('Country / Region', 'wc-conference-checkout'),
            'required' => true,
            'class'    => ['form-row-wide'],
            'priority' => 30,
            // 'default'   => 'GB',
        ];
        $fields['billing']['contact_payer_type'] = [
            'type'        => 'select',
            'label'       => esc_html__('Who are you paying for?', 'wc-conference-checkout'),
            'required'    => true,
            'options'     => [
                ''          => esc_html__('— Please choose —', 'wc-conference-checkout'),
                'self'      => esc_html__('I am paying for myself in order to attend or present at the conference', 'wc-conference-checkout'),
                'behalf'    => esc_html__('I am paying on behalf of someone else attending / presenting at the conference', 'wc-conference-checkout'),
                'multiple'  => esc_html__('I am paying for multiple people to attend or present at the conference', 'wc-conference-checkout'),
            ],
            'class'       => ['form-row-wide'],
            'priority'    => 40,
        ];
        $fields['billing']['contact_payee_email'] = [
            'type'        => 'email',
            'label'       => esc_html__('Email address of payee', 'wc-conference-checkout'),
            'required'    => true,
            'class'       => ['form-row-wide'],
            'priority'    => 50,
            'validate'    => ['email'],
            'placeholder' => esc_html__('payee@example.com', 'wc-conference-checkout'),
        ];
        $fields['billing']['billing_address_1'] = [
            'label'       => esc_html__('Street address', 'wc-conference-checkout'),
            'required'    => true,
            'class'       => ['form-row-wide'],
            'priority'    => 60,
            'placeholder' => esc_html__('House number and street name', 'wc-conference-checkout'),
        ];

        // Ticket blocks
        $priority = 70;
        foreach ( self::cart_ticket_blocks() as $b ) {
            $k = $b['key']; $label = $b['label'];

            $fields['billing']["{$k}_heading"] = [
                'type' => 'text',
                'label' => $label,
                'class' => ['form-row-wide','wccc-ticket-heading'],
                'priority' => $priority,
                'custom_attributes' => ['readonly' => 'readonly','tabindex' => '-1','aria-hidden' => 'true'],
                'default' => $label,
            ]; $priority++;

            $fields['billing']["{$k}_name"] = [
                'type' => 'text',
                'label' => esc_html__('Full Name of Delegate / Attendee', 'wc-conference-checkout'),
                'required' => true,
                'class' => ['form-row-first'],
                'priority' => $priority,
            ]; $priority++;

            $fields['billing']["{$k}_email"] = [
                'type' => 'email',
                'label' => esc_html__('Email Address', 'wc-conference-checkout'),
                'required' => true,
                'class' => ['form-row-last'],
                'priority' => $priority,
                'validate' => ['email'],
            ]; $priority++;

            $fields['billing']["{$k}_phone"] = [
                'type' => 'tel',
                'label' => esc_html__('Phone Number', 'wc-conference-checkout'),
                'required' => true,
                'class' => ['form-row-wide'],
                'priority' => $priority,
            ]; $priority++;

            $fields['billing']["{$k}_presenting"] = [
                'type' => 'checkbox',
                'label' => esc_html__('I am presenting at the conference', 'wc-conference-checkout'),
                'required' => false,
                'class' => ['form-row-wide'],
                'priority' => $priority,
            ]; $priority++;

            $fields['billing']["{$k}_waitlisted"] = [
                'type' => 'checkbox',
                'label' => esc_html__('I have a paper that is currently waitlisted', 'wc-conference-checkout'),
                'required' => false,
                'class' => ['form-row-wide'],
                'priority' => $priority,
            ]; $priority++;
        }

        // Newsletter opt-in LAST (bold/styled via CSS)
        $fields['billing']['newsletter_optin'] = [
            'type'     => 'checkbox',
            'label'    => esc_html( $opts['newsletter_label'] ),
            'required' => false,
            'class'    => ['form-row-wide','wccc-newsletter-optin'],
            'priority' => 9999,
        ];

        return $fields;
    }

    /** ========== Validation ========== */
    public static function validate_checkout() : void {
        if ( empty($_POST['contact_payer_type']) ) {
            wc_add_notice(esc_html__('Please choose who you are paying for.', 'wc-conference-checkout'), 'error');
        }
        if ( empty($_POST['contact_payee_email']) || ! is_email( wp_unslash($_POST['contact_payee_email']) ) ) {
            wc_add_notice(esc_html__('Please enter a valid Email address of payee.', 'wc-conference-checkout'), 'error');
        }

        foreach ( self::cart_ticket_blocks() as $b ) {
            $k = $b['key'];
            if ( empty($_POST["{$k}_name"]) ) {
                wc_add_notice(sprintf(esc_html__('Please enter the Full Name for %s.', 'wc-conference-checkout'), $b['label']), 'error');
            }
            if ( empty($_POST["{$k}_email"]) || ! is_email( wp_unslash($_POST["{$k}_email"]) ) ) {
                wc_add_notice(sprintf(esc_html__('Please enter a valid Email Address for %s.', 'wc-conference-checkout'), $b['label']), 'error');
            }
            if ( empty($_POST["{$k}_phone"]) ) {
                wc_add_notice(sprintf(esc_html__('Please enter the Phone Number for %s.', 'wc-conference-checkout'), $b['label']), 'error');
            }
        }
    }

    /** ========== Save ========== */
    public static function save_order_meta(\WC_Order $order, array $data) : void {
        if ( isset($_POST['contact_payer_type']) ) {
            $order->update_meta_data('Contact: Payer Type', sanitize_text_field( wp_unslash($_POST['contact_payer_type']) ));
        }
        if ( isset($_POST['contact_payee_email']) ) {
            $order->update_meta_data('Contact: Payee Email', sanitize_email( wp_unslash($_POST['contact_payee_email']) ));
        }
        $order->update_meta_data('Contact: Newsletter Consent', ! empty($_POST['newsletter_optin']) ? 'Yes' : 'No');

        foreach ( self::cart_ticket_blocks() as $b ) {
            $k = $b['key']; $prefix = $b['label'];
            $map = [
                "{$k}_name"       => "{$prefix}: Delegate Name",
                "{$k}_email"      => "{$prefix}: Delegate Email",
                "{$k}_phone"      => "{$prefix}: Delegate Phone",
                "{$k}_presenting" => "{$prefix}: Presenting",
                "{$k}_waitlisted" => "{$prefix}: Waitlisted",
            ];
            foreach ($map as $post_key => $meta_key) {
                if ( ! isset($_POST[$post_key]) ) continue;
                $val = $_POST[$post_key];
                if ( self::ends_with($post_key, '_presenting') || self::ends_with($post_key, '_waitlisted') ) {
                    $val = ! empty($val) ? 'Yes' : 'No';
                }
                $v
