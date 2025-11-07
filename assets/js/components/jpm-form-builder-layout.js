/**
 * Form Builder Layout Management
 * Handles row and column organization, width calculations
 */
(function ($) {
  "use strict";

  window.JPMFormBuilderLayout = {
    /**
     * Reorganize rows and update column widths
     */
    reorganizeRows: function () {
      $(".jpm-form-row").each(function () {
        var $row = $(this);
        var $columns = $row.find(".jpm-form-column");
        var $fields = $row.find(".jpm-field-editor");

        // If row has columns, recalculate column widths based on number of columns
        if ($columns.length > 0) {
          var fieldCount = $columns.length;
          var colWidth = window.JPMFormBuilderUtils
            ? window.JPMFormBuilderUtils.calculateColumnWidth(fieldCount)
            : this.calculateColumnWidth(fieldCount);

          $columns.each(function () {
            var $fieldEditor = $(this).find(".jpm-field-editor");
            if ($fieldEditor.length > 0) {
              // Set column width based on number of columns in row
              $fieldEditor.find(".jpm-field-column-width").val(colWidth);
              var colText = window.JPMFormBuilderUtils
                ? window.JPMFormBuilderUtils.getColumnWidthText(colWidth)
                : colWidth == 12
                ? "Full"
                : colWidth + " cols";
              $fieldEditor.find(".jpm-field-column-badge").text(colText);
            }
          });
        } else if ($fields.length > 0) {
          // Single field, no columns - set to full width
          $fields.each(function () {
            var $fieldEditor = $(this);
            $fieldEditor.find(".jpm-field-column-width").val(12);
            $fieldEditor.find(".jpm-field-column-badge").text("Full");
          });
        }
      });
    },

    /**
     * Calculate column width (fallback if utils not available)
     * @param {number} fieldCount - Number of fields
     * @returns {number} Column width
     */
    calculateColumnWidth: function (fieldCount) {
      if (fieldCount === 1) return 12;
      if (fieldCount === 2) return 6;
      if (fieldCount === 3) return 4;
      return 12;
    },

    /**
     * Clean up source row after field is moved
     * @param {jQuery} $sourceRow - Source row element
     * @param {jQuery} $targetRow - Target row element
     */
    cleanupSourceRow: function ($sourceRow, $targetRow) {
      if (!$sourceRow || $sourceRow.length === 0 || $sourceRow.is($targetRow)) {
        return;
      }

      var $remainingFields = $sourceRow.find(".jpm-field-editor");

      if ($remainingFields.length === 0) {
        // No fields left - remove row with animation
        $sourceRow.slideUp(300, function () {
          $(this).remove();
        });
      } else if ($remainingFields.length === 1) {
        // Only one field left - make it full width
        var $singleField = $remainingFields.first();
        var $singleColumn = $singleField.parent();

        if ($singleColumn.hasClass("jpm-form-column")) {
          $singleField.unwrap();
          $sourceRow.removeClass("jpm-row-has-columns");
        }

        // Set to full width
        $singleField.find(".jpm-field-column-width").val(12);
        $singleField.find(".jpm-field-column-badge").text("Full");
      }
    },

    /**
     * Create new row with field
     * @param {jQuery} $field - Field element
     * @param {jQuery} $targetRow - Target row element
     * @param {string} position - 'before' or 'after'
     */
    createNewRowWithField: function ($field, $targetRow, position) {
      var $newRow = $('<div class="jpm-form-row"></div>');

      // Remove column wrapper if exists
      if ($field.parent().hasClass("jpm-form-column")) {
        $field.unwrap();
      }

      $field.appendTo($newRow);

      // Set to full width
      $field.find(".jpm-field-column-width").val(12);
      $field.find(".jpm-field-column-badge").text("Full");

      // Insert row at correct position
      if (position === "before") {
        $targetRow.before($newRow);
      } else {
        $targetRow.after($newRow);
      }

      // Animate row appearance
      $newRow.hide().slideDown(300);
    },

    /**
     * Add field to row as column
     * @param {jQuery} $field - Field element
     * @param {jQuery} $targetRow - Target row element
     * @param {string} side - 'left', 'right', or 'center'
     */
    addFieldToRowAsColumn: function ($field, $targetRow, side) {
      // Ensure row has column structure
      if (!$targetRow.hasClass("jpm-row-has-columns")) {
        $targetRow.addClass("jpm-row-has-columns");

        // Wrap existing fields in columns
        $targetRow
          .find(".jpm-field-editor")
          .not($field)
          .each(function () {
            if (!$(this).parent().hasClass("jpm-form-column")) {
              $(this).wrap('<div class="jpm-form-column"></div>');
            }
          });
      }

      // Wrap field in column if needed
      if (!$field.parent().hasClass("jpm-form-column")) {
        $field.wrap('<div class="jpm-form-column"></div>');
      }

      var $fieldColumn = $field.parent();

      // Check if row already has 3 columns (maximum)
      var columnCount = $targetRow.find(".jpm-form-column").length;

      if (columnCount >= 3) {
        // Max columns reached - create new row instead
        this.createNewRowWithField($field, $targetRow, "after");
        return;
      }

      // Insert based on side
      if (side === "left") {
        $fieldColumn.prependTo($targetRow);
      } else if (side === "right") {
        $fieldColumn.appendTo($targetRow);
      } else {
        // Center - add after first column
        var $firstColumn = $targetRow.find(".jpm-form-column").first();
        if ($firstColumn.length > 0) {
          $fieldColumn.insertAfter($firstColumn);
        } else {
          $fieldColumn.appendTo($targetRow);
        }
      }
    },
  };
})(jQuery);
