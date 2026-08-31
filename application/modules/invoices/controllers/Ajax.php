<?php

if ( ! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/*
 * InvoicePlane
 *
 * @author      InvoicePlane Developers & Contributors
 * @copyright   Copyright (c) 2012 - 2018 InvoicePlane.com
 * @license     https://invoiceplane.com/license.txt
 * @link        https://invoiceplane.com
 */

#[AllowDynamicProperties]
class Ajax extends Admin_Controller
{
    public $ajax_controller = true;

    public function save()
    {
        $this->load->model([
            'invoices/mdl_items',
            'invoices/mdl_invoices',
            'units/mdl_units',
            'invoices/mdl_invoice_sumex',
        ]);

        $invoice_id = $this->security->xss_clean($this->input->post('invoice_id', true));

        $this->mdl_invoices->set_id($invoice_id);

        if ($this->mdl_invoices->run_validation('validation_rules_save_invoice')) {
            $items = json_decode($this->input->post('items'));

            $invoice_discount_percent = (float) $this->input->post('invoice_discount_percent');
            $invoice_discount_amount  = (float) $this->input->post('invoice_discount_amount');

            // Percent by default. Only one allowed. Prevent set 2 global discounts by geeky client - since v1.6.3
            if ($invoice_discount_percent && $invoice_discount_amount) {
                $invoice_discount_amount = 0.0;
            }

            // New discounts (for legacy_calculation false) - since v1.6.3 Need if taxes applied after discounts
            $items_subtotal = 0.0;
            if ($invoice_discount_amount) {
                foreach ($items as $item) {
                    if ( ! empty($item->item_name)) {
                        $items_subtotal += standardize_amount($item->item_quantity) * standardize_amount($item->item_price);
                    }
                }
            }

            // New discounts (for legacy_calculation false) - since v1.6.3 Need if taxes applied after discounts
            $global_discount = [
                'amount'         => $invoice_discount_amount ? standardize_amount($invoice_discount_amount) : 0.0,
                'percent'        => $invoice_discount_percent ? standardize_amount($invoice_discount_percent) : 0.0,
                'item'           => 0.0, // Updated by ref (Need for invoice_item_subtotal calculation in Mdl_invoice_amounts)
                'items_subtotal' => $items_subtotal,
            ];

            // Automatic calculation mode
            if (get_setting('einvoicing')) {
                // Shift to false (by default). Need true? See Dev Note on ipconfig example
                $this->config->set_item('legacy_calculation', ! empty($this->input->post('legacy_calculation')));
            }

            $tax_rate_percent_by_id = [];
            $tax_rates = $this->db->select('tax_rate_id, tax_rate_percent')->get('ip_tax_rates')->result();
            foreach ($tax_rates as $tax_rate) {
                $tax_rate_percent_by_id[(int) $tax_rate->tax_rate_id] = (float) $tax_rate->tax_rate_percent;
            }

            foreach ($items as $item) {
                // Check if an item has either a quantity + price or name or description
                if ( ! empty($item->item_name)) {
                    $has_item_price_gross = property_exists($item, 'item_price_gross') && $item->item_price_gross !== null && $item->item_price_gross !== '';
                    $item_price_source = property_exists($item, 'item_price_source') ? $item->item_price_source : 'net';

                    // Standardize item data
                    $item->item_quantity        = $item->item_quantity ? standardize_amount($item->item_quantity) : 0.0;
                    $item->item_price           = $item->item_price ? standardize_amount($item->item_price) : 0.0;
                    $item->item_price_gross     = $has_item_price_gross ? standardize_amount($item->item_price_gross) : null;
                    $item->item_discount_amount = $item->item_discount_amount ? standardize_amount($item->item_discount_amount) : null;
                    $item->item_product_id      = $item->item_product_id ? $item->item_product_id : null;
                    $item->item_product_unit_id = $item->item_product_unit_id ? $item->item_product_unit_id : null;
                    $item->item_product_unit    = $this->mdl_units->get_name($item->item_product_unit_id, $item->item_quantity);
                    if (property_exists($item, 'item_date')) {
                        $item->item_date = $item->item_date ? date_to_mysql($item->item_date) : null;
                    }

                    if ($has_item_price_gross && $item_price_source === 'gross') {
                        $tax_rate_id = isset($item->item_tax_rate_id) ? (int) $item->item_tax_rate_id : 0;
                        $tax_percent = $tax_rate_percent_by_id[$tax_rate_id] ?? 0.0;
                        $tax_multiplier = 1 + ($tax_percent / 100);

                        $item->item_price = round((float) $item->item_price_gross / $tax_multiplier, 8);
                    }

                    unset($item->item_price_source);

                    $item_id = ($item->item_id) ?: null;
                    unset($item->item_id);

                    if ( ! $item->item_task_id) {
                        unset($item->item_task_id);
                    } else {
                        if (empty($this->mdl_tasks)) {
                            $this->load->model('tasks/mdl_tasks');
                        }

                        $this->mdl_tasks->update_status(4, $item->item_task_id);
                    }

                    $this->mdl_items->save($item_id, $item, $global_discount);
                } elseif (
                    empty($item->item_name)
                    && (
                        ! empty($item->item_quantity)
                        || ! empty($item->item_price)
                        || (property_exists($item, 'item_price_gross') && ! empty($item->item_price_gross))
                    )
                ) {
                    // Throw an error message and use the form validation for that (todo: where the translations of: The .* field is required.)
                    $this->load->library('form_validation');
                    $this->form_validation->set_rules('item_name', trans('item'), 'required');
                    $this->form_validation->run();

                    $response = [
                        'success'           => 0,
                        'validation_errors' => [
                            'item_name' => form_error('item_name', '', ''),
                        ],
                    ];

                    echo json_encode($response);
                    exit;
                }
            }

            $invoice_status_id = $this->input->post('invoice_status_id');

            // Read invoice number from input
            $invoice_number = $this->input->post('invoice_number');

            // Validate invoice_number: only allow safe characters (alphanumeric, dash, underscore, slash, period, space).
            // If invalid characters are present, return a clear validation error instead of silently modifying input.
            if ($invoice_number !== null && $invoice_number !== '') {
                if ( ! preg_match('/^[a-zA-Z0-9\-_\/\.\s]+$/', $invoice_number)) {
                    $response = [
                        'success'           => 0,
                        'validation_errors' => [
                            'invoice_number' => trans('invoice_number') . ' ' . trans('contains_invalid_characters'),
                        ],
                    ];

                    echo json_encode($response);
                    exit;
                }
            }

            if (empty($invoice_number) && $invoice_status_id != 1) {
                $invoice_group_id = $this->mdl_invoices->get_invoice_group_id($invoice_id);
                $invoice_number   = $this->mdl_invoices->get_invoice_number($invoice_group_id);
            }

            // Sometime global discount total value (round) need little adjust to be valid in ZugFerd2.3 standard
            if ( ! config_item('legacy_calculation') && $invoice_discount_amount && $invoice_discount_amount != $global_discount['item']) {
                // Adjust amount to reflect real calculation (cents)
                $invoice_discount_amount = $global_discount['item'];
            }

            $db_array = [
                'invoice_number'           => $invoice_number,
                'invoice_status_id'        => $invoice_status_id,
                'invoice_date_created'     => date_to_mysql($this->input->post('invoice_date_created')),
                'invoice_date_due'         => date_to_mysql($this->input->post('invoice_date_due')),
                'invoice_password'         => $this->security->xss_clean($this->input->post('invoice_password')),
                'invoice_terms'            => $this->security->xss_clean($this->input->post('invoice_terms')),
                'payment_method'           => $this->security->xss_clean($this->input->post('payment_method')),
                'invoice_discount_amount'  => standardize_amount($invoice_discount_amount),
                'invoice_discount_percent' => standardize_amount($invoice_discount_percent),
            ];

            // check if status changed to sent, the feature is enabled and settings is set to sent
            if ($this->config->item('disable_read_only') === false && $invoice_status_id == get_setting('read_only_toggle')) {
                $db_array['is_read_only'] = 1;
            }

            $this->mdl_invoices->save($invoice_id, $db_array);

            $sumexInvoice = $this->mdl_invoices->where('sumex_invoice', $invoice_id)->get()->num_rows();

            if ($sumexInvoice >= 1) {
                $sumex_array = [
                    'sumex_invoice'        => $invoice_id,
                    'sumex_reason'         => $this->security->xss_clean($this->input->post('invoice_sumex_reason')),
                    'sumex_diagnosis'      => $this->security->xss_clean($this->input->post('invoice_sumex_diagnosis')),
                    'sumex_treatmentstart' => date_to_mysql($this->input->post('invoice_sumex_treatmentstart')),
                    'sumex_treatmentend'   => date_to_mysql($this->input->post('invoice_sumex_treatmentend')),
                    'sumex_casedate'       => date_to_mysql($this->input->post('invoice_sumex_casedate')),
                    'sumex_casenumber'     => $this->security->xss_clean($this->input->post('invoice_sumex_casenumber')),
                    'sumex_observations'   => $this->security->xss_clean($this->input->post('invoice_sumex_observations')),
                ];

                $this->mdl_invoice_sumex->save($invoice_id, $sumex_array);
            }

            if (config_item('legacy_calculation')) {
                // Recalculate for discounts
                $this->load->model('invoices/mdl_invoice_amounts');
                $this->mdl_invoice_amounts->calculate($invoice_id, $global_discount);
            }

            $response = [
                'success' => 1,
            ];
        } else {
            log_message('error', '980: I wasnt able to run the validation validation_rules_save_invoice');

            $this->load->helper('json_error');
            $response = [
                'success'           => 0,
                'validation_errors' => json_errors(),
            ];
        }

        // Save all custom fields
        if ($this->input->post('custom')) {
            $db_array = [];

            $values = [];
            foreach ($this->input->post('custom') as $custom) {
                if (preg_match("/^(.*)\[\]$/i", $custom['name'], $matches)) {
                    $values[$matches[1]][] = $custom['value'];
                } else {
                    $values[$custom['name']] = $custom['value'];
                }
            }

            foreach ($values as $key => $value) {
                preg_match("/^custom\[(.*?)\](?:\[\]|)$/", $key, $matches);
                if ($matches) {
                    $db_array[$matches[1]] = $value;
                }
            }

            $this->load->model('custom_fields/mdl_invoice_custom');
            $result = $this->mdl_invoice_custom->save_custom($invoice_id, $db_array);
            if ($result !== true) {
                $response = [
                    'success'           => 0,
                    'validation_errors' => $result,
                ];

                echo json_encode($response);
                exit;
            }
        }

        echo json_encode($response);
        exit;
    }

    public function save_invoice_tax_rate()
    {
        $this->load->model('invoices/mdl_invoice_tax_rates');

        if ($this->mdl_invoice_tax_rates->run_validation()) {
            // Only Legacy calculation have global taxes - since v1.6.3
            config_item('legacy_calculation') && $this->mdl_invoice_tax_rates->save();

            $response = [
                'success' => 1,
            ];
        } else {
            $response = [
                'success'           => 0,
                'validation_errors' => $this->mdl_invoice_tax_rates->validation_errors,
            ];
        }

        $this->json_encode_ajax($response);
    }

    /**
     * @param $invoice_id
     */
    public function delete_item($invoice_id)
    {
        $success = 0;
        $item_id = $this->security->xss_clean($this->input->post('item_id'));
        $this->load->model('mdl_invoices');

        // Only continue if the invoice exists or no item id was provided
        if ($this->mdl_invoices->get_by_id($invoice_id) || empty($item_id)) {
            // Delete invoice item
            $this->load->model('mdl_items');
            $item = $this->mdl_items->delete($item_id);

            // Check if deletion was successful
            if ($item) {
                $success = 1;
                // Mark task as complete from invoiced
                if (isset($item->item_task_id) && $item->item_task_id) {
                    $this->load->model('tasks/mdl_tasks');
                    $this->mdl_tasks->update_status(3, $item->item_task_id);
                }
            }
        }

        // Return the response
        $this->json_encode_ajax(['success' => $success]);
    }

    public function get_item()
    {
        $this->load->model('invoices/mdl_items');

        $item = $this->mdl_items->get_by_id($this->security->xss_clean($this->input->post('item_id', true)));

        $this->json_encode_ajax($item);
    }

    public function modal_copy_invoice()
    {
        $this->load->module('layout');

        $this->load->model([
            'invoices/mdl_invoices',
            'invoice_groups/mdl_invoice_groups',
            'tax_rates/mdl_tax_rates',
            'clients/mdl_clients',
        ]);

        $data = [
            'invoice_groups' => $this->mdl_invoice_groups->get()->result(),
            'tax_rates'      => $this->mdl_tax_rates->get()->result(),
            'invoice_id'     => $this->security->xss_clean($this->input->post('invoice_id')),
            'invoice'        => $this->mdl_invoices->where('ip_invoices.invoice_id', $this->security->xss_clean($this->input->post('invoice_id')))->get()->row(),
            'client'         => $this->mdl_clients->get_by_id($this->input->post('client_id')),
        ];

        $this->layout->load_view('invoices/modal_copy_invoice', $data);
    }

    public function copy_invoice()
    {
        $this->load->model([
            'invoices/mdl_invoices',
            'invoices/mdl_items',
            'invoices/mdl_invoice_tax_rates',
        ]);

        if ($this->mdl_invoices->run_validation()) {
            // Automatic calculation mode
            if (get_setting('einvoicing')) {
                // Shift to false (by default). Need true? See Dev Note on ipconfig example
                $this->config->set_item('legacy_calculation', ! empty($this->input->post('legacy_calculation')));
            }

            $target_id = $this->mdl_invoices->save();
            $source_id = $this->security->xss_clean($this->input->post('invoice_id'));

            $this->mdl_invoices->copy_invoice($source_id, $target_id);

            $response = [
                'success'    => 1,
                'invoice_id' => $target_id,
            ];
        } else {
            $this->load->helper('json_error');
            $response = [
                'success'           => 0,
                'validation_errors' => json_errors(),
            ];
        }

        $this->json_encode_ajax($response);
    }

    public function modal_change_user()
    {
        $this->load->module('layout');
        $this->load->model('users/mdl_users');

        $data = [
            'user_id'    => $this->security->xss_clean($this->input->post('user_id')),
            'invoice_id' => $this->security->xss_clean($this->input->post('invoice_id')),
            'users'      => $this->mdl_users->get_latest(),
        ];

        $this->layout->load_view('layout/ajax/modal_change_user_client', $data);
    }

    public function change_user()
    {
        $this->load->model([
            'invoices/mdl_invoices',
            'users/mdl_users',
        ]);

        // Get the user ID
        $user_id = $this->security->xss_clean($this->input->post('user_id'));
        $user    = $this->mdl_users->where('ip_users.user_id', $user_id)->get()->row();

        if ( ! empty($user)) {
            $invoice_id = $this->security->xss_clean($this->input->post('invoice_id'));

            $db_array = [
                'user_id' => $user_id,
            ];
            $this->db->where('invoice_id', $invoice_id);
            $this->db->update('ip_invoices', $db_array);

            $response = [
                'success'    => 1,
                'invoice_id' => $this->security->xss_clean($invoice_id),
            ];
        } else {
            $this->load->helper('json_error');
            $response = [
                'success'           => 0,
                'validation_errors' => json_errors(),
            ];
        }

        $this->json_encode_ajax($response);
    }

    public function modal_change_client()
    {
        $this->load->module('layout');
        $this->load->model('clients/mdl_clients');

        $data = [
            'client_id'  => $this->security->xss_clean($this->input->post('client_id')),
            'invoice_id' => $this->security->xss_clean($this->input->post('invoice_id')),
            'clients'    => $this->mdl_clients->get_latest(),
        ];

        $this->layout->load_view('layout/ajax/modal_change_user_client', $data);
    }

    public function change_client()
    {
        $this->load->model([
            'invoices/mdl_invoices',
            'clients/mdl_clients',
        ]);

        // Get the client ID
        $client_id = $this->security->xss_clean($this->input->post('client_id'));
        $client    = $this->mdl_clients->where('ip_clients.client_id', $client_id)->get()->row();

        if ( ! empty($client)) {
            $invoice_id = $this->security->xss_clean($this->input->post('invoice_id'));

            $db_array = [
                'client_id' => $client_id,
            ];
            $this->db->where('invoice_id', $invoice_id);
            $this->db->update('ip_invoices', $db_array);

            $response = [
                'success'    => 1,
                'invoice_id' => $this->security->xss_clean($invoice_id),
            ];
        } else {
            $this->load->helper('json_error');
            $response = [
                'success'           => 0,
                'validation_errors' => json_errors(),
            ];
        }

        $this->json_encode_ajax($response);
    }

    public function modal_create_invoice()
    {
        $this->load->module('layout');
        $this->load->model([
            'invoice_groups/mdl_invoice_groups',
            'tax_rates/mdl_tax_rates',
            'clients/mdl_clients',
        ]);

        $data = [
            'invoice_groups' => $this->mdl_invoice_groups->get()->result(),
            'tax_rates'      => $this->mdl_tax_rates->get()->result(),
            'client'         => $this->mdl_clients->get_by_id($this->input->post('client_id')),
            'clients'        => $this->mdl_clients->get_latest(),
        ];

        $this->layout->load_view('invoices/modal_create_invoice', $data);
    }

    public function create()
    {
        $this->load->model('invoices/mdl_invoices');

        if ($this->mdl_invoices->run_validation()) {
            $invoice_id = $this->mdl_invoices->create();

            $response = [
                'success'    => 1,
                'invoice_id' => $invoice_id,
            ];
        } else {
            $this->load->helper('json_error');
            $response = [
                'success'           => 0,
                'validation_errors' => json_errors(),
            ];
        }

        $this->json_encode_ajax($response);
    }

    public function modal_create_room_invoice()
    {
        $this->load->module('layout');
        $this->load->model([
            'tax_rates/mdl_tax_rates',
            'clients/mdl_clients',
            'products/mdl_products',
            'families/mdl_families',
        ]);

        $rooms  = [];
        $family = $this->mdl_families->where('family_name', 'Zimmer')->get()->row();

        if ($family) {
            $this->mdl_products->by_family($family->family_id);
            $rooms = $this->mdl_products->get()->result();
        }

        foreach ($rooms as $room) {
            $room->price_gross = $this->product_gross_price($room);
        }

        $city_tax = $this->mdl_products->where('ip_products.product_name', 'Ortstaxe')->get()->row();
        $cleaning = $this->mdl_products->where('ip_products.product_name', 'Endreinigung')->get()->row();

        $setup_errors = [];

        if (empty($rooms)) {
            $setup_errors[] = trans('room_invoice_family_missing');
        }

        if ( ! $city_tax) {
            $setup_errors[] = trans('room_invoice_product_missing') . ' Ortstaxe';
        }

        if ( ! $cleaning) {
            $setup_errors[] = trans('room_invoice_product_missing') . ' Endreinigung';
        }

        if ( ! get_setting('default_invoice_group')) {
            $setup_errors[] = trans('room_invoice_default_group_missing');
        }

        $data = [
            'tax_rates'            => $this->mdl_tax_rates->get()->result(),
            'client'               => $this->mdl_clients->get_by_id($this->input->post('client_id')),
            'clients'              => $this->mdl_clients->get_latest(),
            'rooms'                => $rooms,
            'cleaning_price_gross' => $cleaning ? $this->product_gross_price($cleaning) : 0.0,
            'setup_errors'         => $setup_errors,
        ];

        $this->layout->load_view('invoices/modal_create_room_invoice', $data);
    }

    public function create_room_invoice()
    {
        $this->load->model([
            'invoices/mdl_invoices',
            'invoices/mdl_items',
            'products/mdl_products',
            'families/mdl_families',
            'tax_rates/mdl_tax_rates',
            'units/mdl_units',
        ]);

        $validation_errors = [];

        // The room must belong to the "Zimmer" product family
        $room    = null;
        $room_id = (int) $this->input->post('room_product_id');
        $family  = $this->mdl_families->where('family_name', 'Zimmer')->get()->row();

        if ($family && $room_id) {
            $this->mdl_products->by_family($family->family_id);
            $room = $this->mdl_products->where('ip_products.product_id', $room_id)->get()->row();
        }

        if ( ! $room) {
            $validation_errors['room_product_id'] = trans('room');
        }

        $price_gross = (float) standardize_amount($this->input->post('room_price_per_night'));

        if ($price_gross <= 0) {
            $validation_errors['room_price_per_night'] = trans('price_per_night_gross');
        }

        $tax_rate_id = (int) $this->input->post('room_tax_rate_id');
        $tax_rate    = $this->mdl_tax_rates->where('tax_rate_id', $tax_rate_id)->get()->row();

        if ( ! $tax_rate) {
            $validation_errors['room_tax_rate_id'] = trans('tax_rate');
        }

        $date_from = $this->parse_user_date($this->input->post('room_date_from'));
        $date_to   = $this->parse_user_date($this->input->post('room_date_to'));

        if ( ! $date_from) {
            $validation_errors['room_date_from'] = trans('from_date');
        }

        if ( ! $date_to || ($date_from && $date_to <= $date_from)) {
            $validation_errors['room_date_to'] = trans('to_date');
        }

        $adults = $this->input->post('room_adults');

        if ( ! ctype_digit((string) $adults) || (int) $adults < 1) {
            $validation_errors['room_adults'] = trans('adults_from_15');
        }

        $city_tax = $this->mdl_products->where('ip_products.product_name', 'Ortstaxe')->get()->row();

        if ( ! $city_tax) {
            $validation_errors['room_product_id'] = trans('room_invoice_product_missing') . ' Ortstaxe';
        }

        $cleaning_enabled = (bool) $this->input->post('room_final_cleaning');
        $cleaning         = null;
        $cleaning_gross   = 0.0;

        if ($cleaning_enabled) {
            $cleaning       = $this->mdl_products->where('ip_products.product_name', 'Endreinigung')->get()->row();
            $cleaning_gross = (float) standardize_amount($this->input->post('room_cleaning_price'));

            if ( ! $cleaning) {
                $validation_errors['room_final_cleaning'] = trans('room_invoice_product_missing') . ' Endreinigung';
            }

            if ($cleaning_gross <= 0) {
                $validation_errors['room_cleaning_price'] = trans('final_cleaning_price_gross');
            }
        }

        if ($validation_errors) {
            $this->json_encode_ajax([
                'success'           => 0,
                'validation_errors' => $validation_errors,
            ]);

            return;
        }

        // Fixed values: default invoice group, no PDF password
        $_POST['invoice_group_id'] = get_setting('default_invoice_group');
        $_POST['invoice_password'] = '';
        $_POST['payment_method']   = get_setting('invoice_default_payment_method');

        if ( ! $this->mdl_invoices->run_validation()) {
            $this->load->helper('json_error');
            $this->json_encode_ajax([
                'success'           => 0,
                'validation_errors' => json_errors(),
            ]);

            return;
        }

        $invoice_id = $this->mdl_invoices->create();

        $nights      = (int) $date_from->diff($date_to)->days;
        $same_year   = $date_from->format('Y') === $date_to->format('Y');
        $description = ($same_year ? $date_from->format('d.m.') : $date_from->format('d.m.Y')) . '-' . $date_to->format('d.m.Y');

        $tax_percent = (float) $tax_rate->tax_rate_percent;

        $items = [
            [
                'invoice_id'           => $invoice_id,
                'item_tax_rate_id'     => $tax_rate_id,
                'item_product_id'      => $room->product_id,
                'item_name'            => $room->product_name,
                'item_description'     => $description,
                'item_quantity'        => $nights,
                'item_price'           => round($price_gross / (1 + $tax_percent / 100), 8),
                'item_price_gross'     => round($price_gross, 8),
                'item_discount_amount' => 0,
                'item_order'           => 1,
                'item_product_unit'    => $this->mdl_units->get_name($room->unit_id, $nights),
                'item_product_unit_id' => $room->unit_id,
            ],
            [
                'invoice_id'           => $invoice_id,
                'item_tax_rate_id'     => (int) $city_tax->tax_rate_id,
                'item_product_id'      => $city_tax->product_id,
                'item_name'            => $city_tax->product_name,
                'item_description'     => (string) $city_tax->product_description,
                'item_quantity'        => $nights * (int) $adults,
                'item_price'           => (float) $city_tax->product_price,
                'item_price_gross'     => $city_tax->product_price_gross,
                'item_discount_amount' => 0,
                'item_order'           => 2,
                'item_product_unit'    => $this->mdl_units->get_name($city_tax->unit_id, $nights * (int) $adults),
                'item_product_unit_id' => $city_tax->unit_id,
            ],
        ];

        if ($cleaning_enabled) {
            $cleaning_tax_percent = (float) $cleaning->tax_rate_percent;

            $items[] = [
                'invoice_id'           => $invoice_id,
                'item_tax_rate_id'     => (int) $cleaning->tax_rate_id,
                'item_product_id'      => $cleaning->product_id,
                'item_name'            => $cleaning->product_name,
                'item_description'     => (string) $cleaning->product_description,
                'item_quantity'        => 1,
                'item_price'           => round($cleaning_gross / (1 + $cleaning_tax_percent / 100), 8),
                'item_price_gross'     => round($cleaning_gross, 8),
                'item_discount_amount' => 0,
                'item_order'           => 3,
                'item_product_unit'    => $this->mdl_units->get_name($cleaning->unit_id, 1),
                'item_product_unit_id' => $cleaning->unit_id,
            ];
        }

        $global_discount = [
            'amount'         => 0.0,
            'percent'        => 0.0,
            'item'           => 0.0,
            'items_subtotal' => 0.0,
        ];

        foreach ($items as $item) {
            $this->mdl_items->save(null, $item, $global_discount);
        }

        $this->json_encode_ajax([
            'success'    => 1,
            'invoice_id' => $invoice_id,
        ]);
    }

    private function product_gross_price($product): float
    {
        if ($product->product_price_gross !== null && $product->product_price_gross !== '') {
            return (float) $product->product_price_gross;
        }

        $tax_percent = (float) ($product->tax_rate_percent ?? 0);

        return round((float) $product->product_price * (1 + $tax_percent / 100), 2);
    }

    private function parse_user_date($date): ?DateTime
    {
        if ( ! is_string($date) || $date === '' || ! is_date($date)) {
            return null;
        }

        $parsed = DateTime::createFromFormat('Y-m-d', date_to_mysql($date));

        return $parsed ? $parsed->setTime(0, 0) : null;
    }

    public function create_recurring()
    {
        $this->load->model('invoices/mdl_invoices_recurring');

        if ($this->mdl_invoices_recurring->run_validation()) {
            $this->mdl_invoices_recurring->save();

            $response = [
                'success' => 1,
            ];
        } else {
            $this->load->helper('json_error');
            $response = [
                'success'           => 0,
                'validation_errors' => json_errors(),
            ];
        }

        $this->json_encode_ajax($response);
    }

    public function modal_create_recurring()
    {
        $this->load->module('layout');

        $this->load->model('mdl_invoices_recurring');

        $data = [
            'invoice_id'        => $this->security->xss_clean($this->input->post('invoice_id')),
            'recur_frequencies' => $this->mdl_invoices_recurring->recur_frequencies,
        ];

        $this->layout->load_view('invoices/modal_create_recurring', $data);
    }

    public function get_recur_start_date()
    {
        $invoice_date    = $this->input->post('invoice_date');
        $recur_frequency = $this->input->post('recur_frequency');

        echo increment_user_date($invoice_date, $recur_frequency);
    }

    public function modal_create_credit()
    {
        $this->load->module('layout');
        $this->load->model([
            'invoices/mdl_invoices',
            'invoice_groups/mdl_invoice_groups',
            'tax_rates/mdl_tax_rates',
        ]);

        $data = [
            'invoice_groups' => $this->mdl_invoice_groups->get()->result(),
            'tax_rates'      => $this->mdl_tax_rates->get()->result(),
            'invoice_id'     => $this->security->xss_clean($this->input->post('invoice_id')),
            'invoice'        => $this->mdl_invoices->where('ip_invoices.invoice_id', $this->security->xss_clean($this->input->post('invoice_id')))->get()->row(),
        ];

        $this->layout->load_view('invoices/modal_create_credit', $data);
    }

    public function create_credit()
    {
        $this->load->model([
            'invoices/mdl_invoices',
            'invoices/mdl_items',
            'invoices/mdl_invoice_tax_rates',
        ]);

        if ($this->mdl_invoices->run_validation()) {
            // Automatic calculation mode
            if (get_setting('einvoicing')) {
                // Shift to false (by default). Need true? See Dev Note on ipconfig example
                $this->config->set_item('legacy_calculation', ! empty($this->input->post('legacy_calculation')));
            }

            $target_id = $this->mdl_invoices->save();
            $source_id = $this->security->xss_clean($this->input->post('invoice_id'));

            $this->mdl_invoices->copy_credit_invoice($source_id, $target_id);

            // Set source invoice to read-only
            if ($this->config->item('disable_read_only') == false) {
                $this->mdl_invoices->where('invoice_id', $source_id);
                $this->mdl_invoices->update('ip_invoices', ['is_read_only' => '1']);
            }

            // Set target invoice to credit invoice
            $this->mdl_invoices->where('invoice_id', $target_id);
            $this->mdl_invoices->update('ip_invoices', ['creditinvoice_parent_id' => $source_id]);

            $this->mdl_invoices->where('invoice_id', $target_id);
            $this->mdl_invoices->update('ip_invoice_amounts', ['invoice_sign' => '-1']);

            $response = [
                'success'    => 1,
                'invoice_id' => $target_id,
            ];
        } else {
            $this->load->helper('json_error');
            $response = [
                'success'           => 0,
                'validation_errors' => json_errors(),
            ];
        }

        $this->json_encode_ajax($response);
    }
}
