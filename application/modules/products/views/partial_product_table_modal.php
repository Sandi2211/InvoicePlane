<div class="table-responsive">
    <table id="products_table" class="table table-hover table-bordered table-striped">
        <tr>
            <th>&nbsp;</th>
            <th><?php _trans('product_sku'); ?></th>
            <th><?php _trans('family_name'); ?></th>
            <th><?php _trans('product_name'); ?></th>
            <th><?php _trans('product_description'); ?></th>
            <th class="amount"><?php _trans('product_price'); ?></th>
            <th class="amount"><?php _trans('product_price'); ?> (Brutto)</th>
        </tr>
        <?php foreach ($products as $product) { if ($product->product_price_gross !== null && $product->product_price_gross !== '') { $product_price_gross = (float) $product->product_price_gross; } else { $tax_rate_percent = $product->tax_rate_id ? (float) $product->tax_rate_percent : 0.0; $product_price_gross = (float) $product->product_price * (1 + ($tax_rate_percent / 100)); } ?>
            <tr class="product">
                <td class="text-left">
                    <input type="checkbox" name="product_ids[]"
                           value="<?php echo $product->product_id; ?>">
                </td>
                <td nowrap class="text-left">
                    <b><?php _htmlsc($product->product_sku); ?></b>
                </td>
                <td>
                    <b><?php _htmlsc($product->family_name); ?></b>
                </td>
                <td>
                    <b><?php _htmlsc($product->product_name); ?></b>
                </td>
                <td>
                    <?php echo nl2br(htmlsc($product->product_description)); ?>
                </td>
                <td class="amount">
                    <?php echo format_currency($product->product_price); ?>
                </td>
                <td class="amount">
                    <?php echo format_currency($product_price_gross); ?>
                </td>
            </tr>
        <?php } ?>

    </table>
</div>
