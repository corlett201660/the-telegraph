jQuery(document).ready(function($) {
    var rowTemplate = $('#qrsg-prompts-table tbody tr:first').clone();
    rowTemplate.find('input, textarea, select').val('');
    
    $('#qrsg-add-prompt-row').on('click', function() {
        var rowCount = $('#qrsg-prompts-table tbody tr').length;
        var newRow = rowTemplate.clone();
        newRow.find('input, textarea, select').each(function() {
            this.name = this.name.replace(/\[\d+\]/, '[' + rowCount + ']');
        });
        $('#qrsg-prompts-table tbody').append(newRow);
    });
    
    $('#qrsg-prompts-table').on('click', '.qrsg-remove-prompt-row', function() {
        if ($('#qrsg-prompts-table tbody tr').length > 1) {
            $(this).closest('tr').remove();
        } else {
            $(this).closest('tr').find('input, textarea, select').val('');
        }
    });
});
