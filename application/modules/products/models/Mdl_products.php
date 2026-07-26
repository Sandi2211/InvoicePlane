<?php

if ( ! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/*
 * InvoicePlane
 *
 * @author		InvoicePlane Developers & Contributors
 * @copyright	Copyright (c) 2012 - 2018 InvoicePlane.com
 * @license		https://invoiceplane.com/license.txt
 * @link		https://invoiceplane.com
 */

#[AllowDynamicProperties]
class Mdl_Products extends Response_Model
{
    public $table = 'ip_products';

    public $primary_key = 'ip_products.product_id';

    public function default_select()
    {
        $this->db->select('SQL_CALC_FOUND_ROWS *', false);
    }

    public function default_order_by()
    {
        $this->db->order_by('ip_families.family_name, ip_products.product_name');
    }

    public function default_join()
    {
        $this->db->join('ip_families', 'ip_families.family_id = ip_products.family_id', 'left');
        $this->db->join('ip_units', 'ip_units.unit_id = ip_products.unit_id', 'left');
        $this->db->join('ip_tax_rates', 'ip_tax_rates.tax_rate_id = ip_products.tax_rate_id', 'left');
    }

    public function by_product($match)
    {
        $this->db->group_start();
        $this->db->like('ip_products.product_sku', $match);
        $this->db->or_like('ip_products.product_name', $match);
        $this->db->or_like('ip_products.product_description', $match);
        $this->db->group_end();
    }

    public function by_family($match)
    {
        $this->db->where('ip_products.family_id', $match);
    }

    /**
     * @return array
     */
    public function validation_rules()
    {
        return [
            'product_sku' => [
                'field' => 'product_sku',
                'label' => trans('product_sku'),
            ],
            'product_name' => [
                'field' => 'product_name',
                'label' => trans('product_name'),
                'rules' => 'required',
            ],
            'product_description' => [
                'field' => 'product_description',
                'label' => trans('product_description'),
            ],
            'product_price' => [
                'field' => 'product_price_gross',
                'label' => trans('product_price') . ' (Brutto)',
                'rules' => 'required',
            ],
            'purchase_price' => [
                'field' => 'purchase_price',
                'label' => trans('purchase_price'),
            ],
            'provider_name' => [
                'field' => 'provider_name',
                'label' => trans('provider_name'),
            ],
            'family_id' => [
                'field' => 'family_id',
                'label' => trans('family'),
                'rules' => 'numeric',
            ],
            'unit_id' => [
                'field' => 'unit_id',
                'label' => trans('unit'),
                'rules' => 'numeric',
            ],
            'tax_rate_id' => [
                'field' => 'tax_rate_id',
                'label' => trans('tax_rate'),
                'rules' => 'numeric',
            ],
            // Sumex
            'product_tariff' => [
                'field' => 'product_tariff',
                'label' => trans('product_tariff'),
            ],
        ];
    }

    /**
     * @return array
     */
    public function db_array()
    {
        $db_array = parent::db_array();

        $tax_rate_id         = ! empty($db_array['tax_rate_id']) ? (int) $db_array['tax_rate_id'] : null;
        $tax_rate_percent    = $this->get_tax_rate_percent($tax_rate_id);
        $product_price_gross = $this->input->post('product_price_gross', true);

        if ($product_price_gross !== null && $product_price_gross !== '') {
            $product_price_gross = (float) standardize_amount($product_price_gross);
            $db_array['product_price_gross'] = round($product_price_gross, 8);

            if ($tax_rate_percent > 0) {
                $tax_multiplier = 1 + ($tax_rate_percent / 100);
                $db_array['product_price'] = round($product_price_gross / $tax_multiplier, 8);
            } else {
                $db_array['product_price'] = round($product_price_gross, 8);
            }
        } else {
            $db_array['product_price_gross'] = null;
            $db_array['product_price']       = (empty($db_array['product_price']) ? null : standardize_amount($db_array['product_price']));
        }

        $db_array['purchase_price'] = (empty($db_array['purchase_price']) ? null : standardize_amount($db_array['purchase_price']));
        $db_array['family_id']      = (empty($db_array['family_id']) ? null : $db_array['family_id']);
        $db_array['unit_id']        = (empty($db_array['unit_id']) ? null : $db_array['unit_id']);
        $db_array['tax_rate_id']    = (empty($db_array['tax_rate_id']) ? null : $db_array['tax_rate_id']);

        return $db_array;
    }

    private function get_tax_rate_percent(?int $tax_rate_id): float
    {
        if ( ! $tax_rate_id) {
            return 0.0;
        }

        $tax_rate = $this->db
            ->select('tax_rate_percent')
            ->where('tax_rate_id', $tax_rate_id)
            ->get('ip_tax_rates')
            ->row();

        return $tax_rate ? (float) $tax_rate->tax_rate_percent : 0.0;
    }
}
