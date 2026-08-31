<script>
    $(function () {
        // Display the create room invoice modal
        $('#create-room-invoice-modal').modal('show');

        // Enable select2 for all selects
        $('.simple-select').select2();

        <?php $this->layout->load_view('clients/script_select2_client_id.js'); ?>

        // Update price and tax rate defaults from the selected room
        $('#room_product_id').change(function () {
            var option = $(this).find('option:selected');
            $('#room_price_per_night').val(option.data('price-gross'));
            $('#room_tax_rate_id').val(option.data('tax-rate-id')).trigger('change');
        });

        // Toggle the cleaning price field
        $('#room_final_cleaning').change(function () {
            $('#room_cleaning_price').prop('disabled', !this.checked);
        });

        // Creates the room invoice
        $('#room_invoice_create_confirm').click(function () {
            $.post("<?php echo site_url('invoices/ajax/create_room_invoice'); ?>", {
                    client_id: $('#create_room_invoice_client_id').val(),
                    invoice_date_created: $('#invoice_date_created').val(),
                    invoice_time_created: '<?php echo date('H:i:s') ?>',
                    user_id: '<?php echo $this->session->userdata('user_id'); ?>',
                    room_product_id: $('#room_product_id').val(),
                    room_price_per_night: $('#room_price_per_night').val(),
                    room_tax_rate_id: $('#room_tax_rate_id').val(),
                    room_date_from: $('#room_date_from').val(),
                    room_date_to: $('#room_date_to').val(),
                    room_adults: $('#room_adults').val(),
                    room_final_cleaning: $('#room_final_cleaning').is(':checked') ? 1 : 0,
                    room_cleaning_price: $('#room_cleaning_price').val()
                },
                function (data) {
                    var response = json_parse(data, <?php echo (int) IP_DEBUG; ?>);
                    if (response.success === 1) {
                        // The validation was successful and invoice was created
                        window.location = "<?php echo site_url('invoices/view'); ?>/" + response.invoice_id;
                    }
                    else {
                        // The validation was not successful
                        $('.control-group').removeClass('has-error');
                        $('.form-group').removeClass('has-error');
                        for (var key in response.validation_errors) {
                            $('#' + key).parent().parent().addClass('has-error');
                        }
                    }
                });
        });
    });

</script>

<div id="create-room-invoice-modal" class="modal modal-lg"
     role="dialog" aria-labelledby="modal_create_room_invoice" aria-hidden="true">
    <form class="modal-content">
        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal"><i class="fa fa-close"></i></button>
            <h4 class="panel-title"><?php _trans('create_room_invoice'); ?></h4>
        </div>
        <div class="modal-body">

            <input class="hidden" id="input_permissive_search_clients"
                   value="<?php echo html_escape(get_setting('enable_permissive_search_clients')); ?>">

<?php if ( ! empty($setup_errors)) : ?>
            <div class="alert alert-danger">
<?php foreach ($setup_errors as $setup_error) : ?>
                <div><?php _htmlsc($setup_error); ?></div>
<?php endforeach; ?>
            </div>
<?php endif; ?>

            <div class="form-group has-feedback">
                <label for="create_room_invoice_client_id"><?php _trans('client'); ?></label>
                <div class="input-group">
                    <span id="toggle_permissive_search_clients" class="input-group-addon" title="<?php _trans('enable_permissive_search_clients'); ?>" style="cursor:pointer;">
                        <i class="fa fa-toggle-<?php echo get_setting('enable_permissive_search_clients') ? 'on' : 'off' ?> fa-fw"></i>
                    </span>
                    <select name="client_id" id="create_room_invoice_client_id" class="client-id-select form-control"
                            autofocus="autofocus" required>
<?php if ( ! empty($client)) : ?>
                        <option value="<?php echo $client->client_id; ?>"><?php _htmlsc(format_client($client, false)); ?></option>
<?php endif; ?>
                    </select>
                </div>
            </div>

            <div class="form-group has-feedback">
                <label for="invoice_date_created"><?php _trans('invoice_date'); ?></label>

                <div class="input-group">
                    <input name="invoice_date_created" id="invoice_date_created"
                           class="form-control datepicker"
                           value="<?php echo date(date_format_setting()); ?>" required>
                    <span class="input-group-addon">
                    <i class="fa fa-calendar fa-fw"></i>
                </span>
                </div>
            </div>

            <div class="form-group">
                <label for="room_product_id"><?php _trans('room'); ?></label>
                <select name="room_product_id" id="room_product_id"
                        class="form-control simple-select" data-minimum-results-for-search="Infinity" required>
<?php foreach ($rooms as $room) : ?>
                    <option value="<?php echo (int) $room->product_id; ?>"
                            data-price-gross="<?php echo html_escape(format_amount($room->price_gross)); ?>"
                            data-tax-rate-id="<?php echo (int) $room->tax_rate_id; ?>">
                        <?php _htmlsc($room->product_name); ?>
                    </option>
<?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="room_price_per_night"><?php _trans('price_per_night_gross'); ?></label>
                <input type="text" name="room_price_per_night" id="room_price_per_night" class="form-control"
                       value="<?php echo empty($rooms) ? '' : html_escape(format_amount($rooms[0]->price_gross)); ?>"
                       autocomplete="off" required>
            </div>

            <div class="form-group">
                <label for="room_tax_rate_id"><?php _trans('tax_rate'); ?></label>
                <select name="room_tax_rate_id" id="room_tax_rate_id"
                        class="form-control simple-select" data-minimum-results-for-search="Infinity" required>
<?php foreach ($tax_rates as $tax_rate) : ?>
                    <option value="<?php echo (int) $tax_rate->tax_rate_id; ?>"
                        <?php echo ( ! empty($rooms) && $rooms[0]->tax_rate_id == $tax_rate->tax_rate_id) ? ' selected="selected"' : ''; ?>>
                        <?php _htmlsc(format_amount($tax_rate->tax_rate_percent) . '% - ' . $tax_rate->tax_rate_name); ?>
                    </option>
<?php endforeach; ?>
                </select>
            </div>

            <div class="form-group has-feedback">
                <label for="room_date_from"><?php _trans('from_date'); ?></label>
                <div class="input-group">
                    <input name="room_date_from" id="room_date_from"
                           class="form-control datepicker"
                           value="<?php echo date(date_format_setting(), strtotime('-1 day')); ?>" required>
                    <span class="input-group-addon">
                        <i class="fa fa-calendar fa-fw"></i>
                    </span>
                </div>
            </div>

            <div class="form-group has-feedback">
                <label for="room_date_to"><?php _trans('to_date'); ?></label>
                <div class="input-group">
                    <input name="room_date_to" id="room_date_to"
                           class="form-control datepicker"
                           value="<?php echo date(date_format_setting()); ?>" required>
                    <span class="input-group-addon">
                        <i class="fa fa-calendar fa-fw"></i>
                    </span>
                </div>
            </div>

            <div class="form-group">
                <label for="room_adults"><?php _trans('adults_from_15'); ?></label>
                <input type="number" name="room_adults" id="room_adults" class="form-control"
                       value="2" min="1" step="1" required>
            </div>

            <div class="form-group">
                <label for="room_final_cleaning">
                    <input type="checkbox" name="room_final_cleaning" id="room_final_cleaning" value="1" checked>
                    <?php _trans('final_cleaning'); ?>
                </label>
            </div>

            <div class="form-group">
                <label for="room_cleaning_price"><?php _trans('final_cleaning_price_gross'); ?></label>
                <input type="text" name="room_cleaning_price" id="room_cleaning_price" class="form-control"
                       value="<?php echo html_escape(format_amount($cleaning_price_gross)); ?>"
                       autocomplete="off">
            </div>

        </div>

        <div class="modal-footer">
            <div class="btn-group">
                <button class="btn btn-success ajax-loader" id="room_invoice_create_confirm" type="button"
                    <?php echo empty($setup_errors) ? '' : ' disabled'; ?>>
                    <i class="fa fa-check"></i> <?php _trans('submit'); ?>
                </button>
                <button class="btn btn-danger" type="button" data-dismiss="modal">
                    <i class="fa fa-times"></i> <?php _trans('cancel'); ?>
                </button>
            </div>
        </div>
    </form>
</div>
