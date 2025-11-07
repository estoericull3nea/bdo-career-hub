// Handle bulk actions
jQuery("#jpm-bulk-form").on("submit", function (e) {
  e.preventDefault();
  // AJAX call similar to above
});

// Form Builder JavaScript
jQuery(document).ready(function ($) {
  var fieldIndex = $(".jpm-field-editor").length;

  // Show field type selection
  $("#jpm-add-field-btn").on("click", function () {
    $("#jpm-field-types").slideToggle();
  });

  // Add field when type is selected
  $(".jpm-field-type-btn").on("click", function () {
    var fieldType = $(this).data("type");
    addField(fieldType);
    $("#jpm-field-types").slideUp();
  });

  // Toggle field editor
  $(document).on("click", ".jpm-field-toggle", function () {
    var content = $(this)
      .closest(".jpm-field-editor")
      .find(".jpm-field-content");
    content.slideToggle();
    $(this)
      .find(".dashicons")
      .toggleClass("dashicons-arrow-down dashicons-arrow-up");
  });

  // Remove field
  $(document).on("click", ".jpm-field-remove", function () {
    if (confirm("Are you sure you want to remove this field?")) {
      var $fieldEditor = $(this).closest(".jpm-field-editor");
      var $row = $fieldEditor.closest(".jpm-form-row");
      var $column = $fieldEditor.closest(".jpm-form-column");

      $fieldEditor.fadeOut(300, function () {
        $fieldEditor.remove();

        // Remove column wrapper if it exists
        if ($column.length > 0) {
          $column.remove();
        }

        // Check remaining fields in row
        var $remainingFields = $row.find(".jpm-field-editor");
        var $remainingColumns = $row.find(".jpm-form-column");

        if ($remainingFields.length === 0) {
          // No fields left, remove row
          $row.remove();
        } else if ($remainingFields.length === 1) {
          // Only one field left - remove columns and make it full width
          var $singleField = $remainingFields.first();
          var $singleColumn = $singleField.parent();

          if ($singleColumn.hasClass("jpm-form-column")) {
            $singleField.unwrap();
            $row.removeClass("jpm-row-has-columns");
          }

          // Set to full width
          $singleField.find(".jpm-field-column-width").val(12);
          $singleField.find(".jpm-field-column-badge").text("Full");
        } else if ($remainingColumns.length > 0) {
          // Multiple fields remain - recalculate column widths
          // This will be handled by reorganizeRows()
        } else {
          // Fields exist but no columns - make them full width
          $remainingFields.each(function () {
            var $field = $(this);
            $field.find(".jpm-field-column-width").val(12);
            $field.find(".jpm-field-column-badge").text("Full");
          });
        }

        reorganizeRows();
        updateFormFields();
        initializeSortable();
      });
    }
  });

  // Update field title when label changes
  $(document).on("input", ".jpm-field-label", function () {
    var label = $(this).val() || "Untitled Field";
    $(this).closest(".jpm-field-editor").find(".jpm-field-title").text(label);
    updateFormFields();
  });

  // Update field name when label changes (auto-generate)
  $(document).on("input", ".jpm-field-label", function () {
    var label = $(this).val();
    var nameField = $(this)
      .closest(".jpm-field-editor")
      .find(".jpm-field-name");
    if (!nameField.val() || nameField.data("auto-generated")) {
      var name = label
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, "_")
        .replace(/^_+|_+$/g, "");
      nameField.val(name).data("auto-generated", true);
    }
    updateFormFields();
  });

  // Update form fields when any field property changes
  $(document).on(
    "change input",
    ".jpm-field-editor input, .jpm-field-editor select, .jpm-field-editor textarea",
    function () {
      updateFormFields();
    }
  );

  // Show/hide options field based on field type
  $(document).on("change", ".jpm-field-type", function () {
    var fieldType = $(this).val();
    var optionsRow = $(this)
      .closest(".jpm-field-editor")
      .find(".jpm-field-options-row");
    if (["select", "radio", "checkbox"].includes(fieldType)) {
      optionsRow.slideDown();
    } else {
      optionsRow.slideUp();
    }
    updateFormFields();
  });

  // Add new field
  function addField(fieldType) {
    $.ajax({
      url: jpm_ajax.ajax_url,
      type: "POST",
      data: {
        action: "jpm_add_field",
        field_type: fieldType,
        index: fieldIndex,
        nonce: jpm_ajax.nonce,
      },
      success: function (response) {
        if (response.success) {
          // Create new row for each field
          var rowIndex = $(".jpm-form-row").length;
          var $newRow = $(
            "<div class='jpm-form-row' data-row-index='" + rowIndex + "'></div>"
          );
          $newRow.html(response.data.html);
          $(".jpm-form-rows").append($newRow);

          fieldIndex++;
          reorganizeRows();
          updateFormFields();
          initializeSortable();
        }
      },
    });
  }

  // Calculate column width based on number of fields in row
  function calculateColumnWidth(fieldCount) {
    if (fieldCount === 1) return 12;
    if (fieldCount === 2) return 6;
    if (fieldCount === 3) return 4;
    return 12; // Default to full width
  }

  // Reorganize rows and update column widths
  function reorganizeRows() {
    $(".jpm-form-row").each(function () {
      var $row = $(this);
      var $columns = $row.find(".jpm-form-column");
      var $fields = $row.find(".jpm-field-editor");

      // If row has columns, recalculate column widths based on number of columns
      if ($columns.length > 0) {
        var fieldCount = $columns.length;
        var colWidth = calculateColumnWidth(fieldCount);

        $columns.each(function () {
          var $fieldEditor = $(this).find(".jpm-field-editor");
          if ($fieldEditor.length > 0) {
            // Set column width based on number of columns in row
            $fieldEditor.find(".jpm-field-column-width").val(colWidth);
            var colText = colWidth == 12 ? "Full" : colWidth + " cols";
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
  }

  // Initialize sortable for drag-and-drop
  function initializeSortable() {
    if (!$.fn.draggable || !$.fn.droppable) return;

    // Destroy existing draggable/droppable instances safely
    $(".jpm-field-editor").each(function () {
      if ($(this).data("ui-draggable")) {
        $(this).draggable("destroy");
      }
    });

    $(".jpm-form-row, .jpm-form-column").each(function () {
      if ($(this).data("ui-droppable")) {
        $(this).droppable("destroy");
      }
    });

    // Make each field individually draggable
    $(".jpm-field-editor").each(function () {
      var $field = $(this);

      $field.draggable({
        handle: ".jpm-field-header",
        cursor: "move",
        revert: "invalid",
        helper: "clone",
        opacity: 0.7,
        zIndex: 1000,
        scroll: false,
        appendTo: "body",
        start: function (event, ui) {
          // Store original position and make original semi-transparent
          var $originalParent = $field.parent();
          var $originalRow = $field.closest(".jpm-form-row");
          $field.data("original-parent", $originalParent);
          $field.data("original-row", $originalRow);
          $field.css("opacity", "0.3");
          // Mark helper as not dropped yet
          ui.helper.data("dropped", false);
          // Store original row in helper too
          ui.helper.data("original-row", $originalRow);
        },
        stop: function (event, ui) {
          // Restore opacity if drop was invalid (reverted)
          if (!ui.helper || !ui.helper.data("dropped")) {
            $field.css("opacity", "1");
          }
        },
      });
    });

    // Make rows and columns droppable - initialize each individually
    $(".jpm-form-row, .jpm-form-column").each(function () {
      var $target = $(this);
      $target.droppable({
        accept: ".jpm-field-editor",
        hoverClass: "jpm-drop-hover",
        tolerance: "pointer",
        greedy: false,
        activeClass: "jpm-drop-active",
        over: function (event, ui) {
          var $target = $(this);
          var $targetRow = $target.hasClass("jpm-form-row")
            ? $target
            : $target.closest(".jpm-form-row");
          var $targetColumn = $target.hasClass("jpm-form-column")
            ? $target
            : null;

          // Get mouse position relative to the target
          var targetOffset = $target.offset();
          var targetWidth = $target.width();
          var targetHeight = $target.height();
          var mouseX = event.pageX - targetOffset.left;
          var mouseY = event.pageY - targetOffset.top;

          // Calculate zones
          var topThreshold = targetHeight * 0.1;
          var bottomThreshold = targetHeight * 0.5;
          var leftThreshold = targetWidth * 0.1;
          var rightThreshold = targetWidth * 0.5;

          var isInTopZone = mouseY < topThreshold;
          var isInBottomZone = mouseY > bottomThreshold;
          var isInLeftZone = mouseX < leftThreshold;
          var isInRightZone = mouseX > rightThreshold;
          var isInMiddleZone = !isInTopZone && !isInBottomZone;

          // Remove all position classes first
          $target.removeClass(
            "jpm-drop-top jpm-drop-bottom jpm-drop-left jpm-drop-right jpm-drop-middle"
          );

          // Add visual feedback based on position
          if (isInTopZone) {
            $target.addClass("jpm-drop-hover jpm-drop-top");
          } else if (isInBottomZone) {
            $target.addClass("jpm-drop-hover jpm-drop-bottom");
          } else if (isInMiddleZone) {
            $target.addClass("jpm-drop-hover jpm-drop-middle");
            if (isInLeftZone) {
              $target.addClass("jpm-drop-left");
            } else if (isInRightZone) {
              $target.addClass("jpm-drop-right");
            }
          }
        },
        out: function (event, ui) {
          // Remove all hover and position classes when leaving
          $(this).removeClass(
            "jpm-drop-hover jpm-drop-top jpm-drop-bottom jpm-drop-left jpm-drop-right jpm-drop-middle"
          );
        },
        drop: function (event, ui) {
          var $draggedField = ui.draggable;
          var $target = $(this);
          var $targetRow = $target.hasClass("jpm-form-row")
            ? $target
            : $target.closest(".jpm-form-row");
          var $targetColumn = $target.hasClass("jpm-form-column")
            ? $target
            : null;

          // Get source row from stored data (more reliable than closest after detach)
          var $sourceRow =
            ui.helper.data("original-row") ||
            $draggedField.data("original-row");

          // If still no source row, try to find it by field index
          if (!$sourceRow || $sourceRow.length === 0) {
            var fieldIndex = $draggedField.attr("data-index");
            $sourceRow = $(".jpm-form-row").has(
              ".jpm-field-editor[data-index='" + fieldIndex + "']"
            );
          }

          var $sourceColumn = $draggedField.data("original-parent")
            ? $draggedField.data("original-parent").closest(".jpm-form-column")
            : $draggedField.closest(".jpm-form-column");

          // Prevent default behavior and stop propagation
          event.stopPropagation();
          event.preventDefault();

          // Mark helper as dropped to prevent revert
          if (ui.helper) {
            ui.helper.data("dropped", true);
            // Remove helper after a short delay
            setTimeout(function () {
              if (ui.helper && ui.helper.parent().length > 0) {
                ui.helper.remove();
              }
            }, 100);
          }

          // Store field index for later removal
          var fieldIndex = $draggedField.attr("data-index");

          // If dropped on the same row, check if we should create columns or reorder
          if ($sourceRow.is($targetRow)) {
            // Find the actual dragged field in the DOM (not the helper)
            var $actualDraggedField = $targetRow.find(
              ".jpm-field-editor[data-index='" + fieldIndex + "']"
            );

            // Find all fields in the row excluding the dragged field
            var $allFields = $targetRow
              .find(".jpm-field-editor")
              .not($actualDraggedField);

            // Get mouse position relative to the row for zone detection
            var rowOffset = $targetRow.offset();
            var rowWidth = $targetRow.width();
            var rowHeight = $targetRow.height();
            var mouseX = event.pageX - rowOffset.left;
            var mouseY = event.pageY - rowOffset.top;

            // Calculate vertical threshold (top 30% or bottom 30% = new row, middle 40% = columns)
            var topThreshold = rowHeight * 0.3;
            var bottomThreshold = rowHeight * 0.7;
            var isInTopZone = mouseY < topThreshold;
            var isInBottomZone = mouseY > bottomThreshold;

            // If only one field in row (the dragged field itself), check drop position relative to the field
            if ($allFields.length === 0) {
              // Single field in row - check position relative to the field itself
              if ($actualDraggedField.length > 0) {
                var fieldOffset = $actualDraggedField.offset();
                var fieldHeight = $actualDraggedField.height();
                var fieldMouseY = event.pageY - fieldOffset.top;

                // Calculate vertical threshold relative to the field
                var fieldTopThreshold = fieldHeight * 0.3;
                var fieldBottomThreshold = fieldHeight * 0.7;
                var isInFieldTopZone = fieldMouseY < fieldTopThreshold;
                var isInFieldBottomZone = fieldMouseY > fieldBottomThreshold;

                // If dropped on top or bottom of the field, create new row
                if (isInFieldTopZone || isInFieldBottomZone) {
                  // Create new row
                  var rowIndex = $targetRow.index();
                  var $newRow = $(
                    "<div class='jpm-form-row' data-row-index='" +
                      rowIndex +
                      "'></div>"
                  );

                  // Remove actual dragged field from current position first
                  var $actualColumn = $actualDraggedField.parent();
                  if ($actualColumn.hasClass("jpm-form-column")) {
                    $actualDraggedField.detach();
                    if ($actualColumn.find(".jpm-field-editor").length === 0) {
                      $actualColumn.remove();
                    }
                  } else {
                    $actualDraggedField.detach();
                  }

                  // Ensure field is not wrapped in a column and set to full width
                  if (
                    $actualDraggedField.parent().hasClass("jpm-form-column")
                  ) {
                    $actualDraggedField.unwrap();
                  }

                  $actualDraggedField.appendTo($newRow);

                  // Set to full width (12 columns)
                  $actualDraggedField.find(".jpm-field-column-width").val(12);
                  $actualDraggedField
                    .find(".jpm-field-column-badge")
                    .text("Full");

                  // Insert row based on position
                  if (isInFieldTopZone) {
                    $targetRow.before($newRow);
                  } else {
                    $targetRow.after($newRow);
                  }

                  $actualDraggedField.css("opacity", "1");

                  // Remove all position classes and labels
                  $(".jpm-form-row, .jpm-form-column").removeClass(
                    "jpm-drop-hover jpm-drop-top jpm-drop-bottom jpm-drop-left jpm-drop-right jpm-drop-middle"
                  );

                  // Reorganize and update
                  reorganizeRows();
                  updateFormFields();
                  setTimeout(function () {
                    initializeSortable();
                  }, 100);
                  return;
                } else {
                  // Middle zone - field stays in place
                  $actualDraggedField.css("opacity", "1");

                  // Remove all position classes and labels
                  $(".jpm-form-row, .jpm-form-column").removeClass(
                    "jpm-drop-hover jpm-drop-top jpm-drop-bottom jpm-drop-left jpm-drop-right jpm-drop-middle"
                  );

                  // Reorganize and update
                  reorganizeRows();
                  updateFormFields();
                  setTimeout(function () {
                    initializeSortable();
                  }, 100);
                  return;
                }
              } else {
                // Fallback - use row position
                if (isInTopZone || isInBottomZone) {
                  // Create new row
                  var rowIndex = $targetRow.index();
                  var $newRow = $(
                    "<div class='jpm-form-row' data-row-index='" +
                      rowIndex +
                      "'></div>"
                  );

                  // Remove dragged field from current position
                  if ($draggedField.parent().hasClass("jpm-form-column")) {
                    $draggedField.unwrap();
                  }
                  $draggedField.detach();

                  // Ensure field is not wrapped in a column
                  if ($draggedField.parent().hasClass("jpm-form-column")) {
                    $draggedField.unwrap();
                  }

                  $draggedField.appendTo($newRow);

                  // Set to full width (12 columns)
                  $draggedField.find(".jpm-field-column-width").val(12);
                  $draggedField.find(".jpm-field-column-badge").text("Full");

                  // Insert row based on position
                  if (isInTopZone) {
                    $targetRow.before($newRow);
                  } else {
                    $targetRow.after($newRow);
                  }

                  $draggedField.css("opacity", "1");

                  // Remove all position classes and labels
                  $(".jpm-form-row, .jpm-form-column").removeClass(
                    "jpm-drop-hover jpm-drop-top jpm-drop-bottom jpm-drop-left jpm-drop-right jpm-drop-middle"
                  );

                  // Reorganize and update
                  reorganizeRows();
                  updateFormFields();
                  setTimeout(function () {
                    initializeSortable();
                  }, 100);
                  return;
                } else {
                  // Middle zone - field stays in place
                  $draggedField.css("opacity", "1");

                  // Remove all position classes and labels
                  $(".jpm-form-row, .jpm-form-column").removeClass(
                    "jpm-drop-hover jpm-drop-top jpm-drop-bottom jpm-drop-left jpm-drop-right jpm-drop-middle"
                  );

                  // Reorganize and update
                  reorganizeRows();
                  updateFormFields();
                  setTimeout(function () {
                    initializeSortable();
                  }, 100);
                  return;
                }
              }
            } else if ($allFields.length > 0) {
              // Find the target field (the one closest to the drop position)
              var $targetField = null;
              var minDistance = Infinity;
              var targetFieldOffset = null;
              var targetFieldHeight = 0;

              $allFields.each(function () {
                var $field = $(this);
                var fieldOffset = $field.offset();
                var fieldCenterX = fieldOffset.left + $field.width() / 2;
                var fieldCenterY = fieldOffset.top + $field.height() / 2;
                var distance = Math.sqrt(
                  Math.pow(event.pageX - fieldCenterX, 2) +
                    Math.pow(event.pageY - fieldCenterY, 2)
                );

                if (distance < minDistance) {
                  minDistance = distance;
                  $targetField = $field;
                  targetFieldOffset = fieldOffset;
                  targetFieldHeight = $field.height();
                }
              });

              // Get mouse position relative to the target field if found, otherwise use row
              var mouseX, mouseY;
              var topThreshold, bottomThreshold;
              var isInTopZone, isInBottomZone;

              if ($targetField && $targetField.length > 0) {
                // Use target field's position
                mouseX = event.pageX - targetFieldOffset.left;
                mouseY = event.pageY - targetFieldOffset.top;
                topThreshold = targetFieldHeight * 0.3;
                bottomThreshold = targetFieldHeight * 0.7;
                isInTopZone = mouseY < topThreshold;
                isInBottomZone = mouseY > bottomThreshold;
              } else {
                // Fallback to row position
                var rowOffset = $targetRow.offset();
                var rowWidth = $targetRow.width();
                var rowHeight = $targetRow.height();
                mouseX = event.pageX - rowOffset.left;
                mouseY = event.pageY - rowOffset.top;
                topThreshold = rowHeight * 0.3;
                bottomThreshold = rowHeight * 0.7;
                isInTopZone = mouseY < topThreshold;
                isInBottomZone = mouseY > bottomThreshold;
              }

              // Determine if dropped on left or right half
              var isLeft =
                mouseX <
                ($targetField ? $targetField.width() : $targetRow.width()) / 2;

              // If dropped in top or bottom zone
              if (isInTopZone || isInBottomZone) {
                // If we have a target field, place it relative to that field in the same row
                if ($targetField && $targetField.length > 0) {
                  // Place the field relative to the target field in the same row
                  // Remove actual dragged field from current position first
                  if ($actualDraggedField.length > 0) {
                    var $actualColumn = $actualDraggedField.parent();
                    if ($actualColumn.hasClass("jpm-form-column")) {
                      $actualDraggedField.detach();
                      if (
                        $actualColumn.find(".jpm-field-editor").length === 0
                      ) {
                        $actualColumn.remove();
                      }
                    } else {
                      $actualDraggedField.detach();
                    }
                  } else {
                    // Fallback to dragged field if actual not found
                    if ($draggedField.parent().hasClass("jpm-form-column")) {
                      $draggedField.unwrap();
                    }
                    $draggedField.detach();
                  }

                  // Get target field's column wrapper (or create one)
                  var $targetColumn = $targetField.parent();
                  if (!$targetColumn.hasClass("jpm-form-column")) {
                    // Create column wrapper if it doesn't exist
                    if (!$targetRow.hasClass("jpm-row-has-columns")) {
                      $targetRow.addClass("jpm-row-has-columns");
                    }
                    $targetField.wrap("<div class='jpm-form-column'></div>");
                    $targetColumn = $targetField.parent();
                  }

                  // Use actual field if available, otherwise use dragged field
                  var $fieldToMove =
                    $actualDraggedField.length > 0
                      ? $actualDraggedField
                      : $draggedField;

                  // Wrap field in column if not already
                  if (!$fieldToMove.parent().hasClass("jpm-form-column")) {
                    $fieldToMove.wrap("<div class='jpm-form-column'></div>");
                  }

                  // Insert based on position (top = before, bottom = after)
                  if (isInTopZone) {
                    $fieldToMove.parent().insertBefore($targetColumn);
                  } else {
                    $fieldToMove.parent().insertAfter($targetColumn);
                  }

                  $fieldToMove.css("opacity", "1");
                } else {
                  // No target field found, create new row above/below
                  // Create new row
                  var rowIndex = $targetRow.index();
                  var $newRow = $(
                    "<div class='jpm-form-row' data-row-index='" +
                      rowIndex +
                      "'></div>"
                  );

                  // Remove actual dragged field from current position first
                  if ($actualDraggedField.length > 0) {
                    var $actualColumn = $actualDraggedField.parent();
                    if ($actualColumn.hasClass("jpm-form-column")) {
                      $actualDraggedField.detach();
                      if (
                        $actualColumn.find(".jpm-field-editor").length === 0
                      ) {
                        $actualColumn.remove();
                      }
                    } else {
                      $actualDraggedField.detach();
                    }
                    $actualDraggedField.appendTo($newRow);
                  } else {
                    // Fallback to dragged field if actual not found
                    if ($draggedField.parent().hasClass("jpm-form-column")) {
                      $draggedField.unwrap();
                    }
                    $draggedField.detach();
                    $draggedField.appendTo($newRow);
                  }

                  // Insert row based on position
                  if (isInTopZone) {
                    $targetRow.before($newRow);
                  } else {
                    $targetRow.after($newRow);
                  }

                  // Check if original row now has only one field - make it full width
                  var $remainingFields = $targetRow.find(".jpm-field-editor");
                  var $remainingColumns = $targetRow.find(".jpm-form-column");

                  if ($remainingFields.length === 1) {
                    // Only one field left - remove columns and make it full width
                    var $singleField = $remainingFields.first();
                    var $singleColumn = $singleField.parent();

                    if ($singleColumn.hasClass("jpm-form-column")) {
                      $singleField.unwrap();
                      $targetRow.removeClass("jpm-row-has-columns");
                    }

                    // Set to full width
                    $singleField.find(".jpm-field-column-width").val(12);
                    $singleField.find(".jpm-field-column-badge").text("Full");
                  } else if ($remainingColumns.length > 0) {
                    // Multiple fields remain - recalculate column widths
                    // This will be handled by reorganizeRows()
                  }

                  $draggedField.css("opacity", "1");
                }
              } else {
                // Middle zone - create columns for left/right positioning
                // Use the target field we already found above
                if ($targetField && $targetField.length > 0) {
                  // Remove actual dragged field from current position first
                  var $actualColumn =
                    $actualDraggedField.length > 0
                      ? $actualDraggedField.parent()
                      : $draggedField.parent();
                  var wasInColumn = $actualColumn.hasClass("jpm-form-column");

                  if ($actualDraggedField.length > 0) {
                    $actualDraggedField.detach();
                    // Check if column is now empty and remove it
                    if (
                      wasInColumn &&
                      $actualColumn.find(".jpm-field-editor").length === 0
                    ) {
                      $actualColumn.remove();
                    }
                  } else {
                    // Fallback to dragged field
                    $draggedField.detach();
                    if (
                      wasInColumn &&
                      $actualColumn.find(".jpm-field-editor").length === 0
                    ) {
                      $actualColumn.remove();
                    }
                  }

                  // Get target field's column wrapper (or create one)
                  var $targetColumn = $targetField.parent();
                  if (!$targetColumn.hasClass("jpm-form-column")) {
                    // Create column wrapper if it doesn't exist
                    if (!$targetRow.hasClass("jpm-row-has-columns")) {
                      $targetRow.addClass("jpm-row-has-columns");
                    }
                    $targetField.wrap("<div class='jpm-form-column'></div>");
                    $targetColumn = $targetField.parent();
                  }

                  // Use actual field if available, otherwise use dragged field
                  var $fieldToMove =
                    $actualDraggedField.length > 0
                      ? $actualDraggedField
                      : $draggedField;

                  // Wrap field in column if not already
                  if (!$fieldToMove.parent().hasClass("jpm-form-column")) {
                    $fieldToMove.wrap("<div class='jpm-form-column'></div>");
                  }

                  // Insert based on position
                  if (isLeft) {
                    $fieldToMove.parent().insertBefore($targetColumn);
                  } else {
                    $fieldToMove.parent().insertAfter($targetColumn);
                  }

                  $fieldToMove.css("opacity", "1");
                } else {
                  // No target field found - place field in row based on left/right position
                  // Remove actual dragged field from current position first
                  var $actualColumn =
                    $actualDraggedField.length > 0
                      ? $actualDraggedField.parent()
                      : $draggedField.parent();
                  var wasInColumn = $actualColumn.hasClass("jpm-form-column");

                  var $fieldToMove =
                    $actualDraggedField.length > 0
                      ? $actualDraggedField
                      : $draggedField;

                  if ($actualDraggedField.length > 0) {
                    $actualDraggedField.detach();
                    // Check if column is now empty and remove it
                    if (
                      wasInColumn &&
                      $actualColumn.find(".jpm-field-editor").length === 0
                    ) {
                      $actualColumn.remove();
                    }
                  } else {
                    // Fallback to dragged field
                    $draggedField.detach();
                    if (
                      wasInColumn &&
                      $actualColumn.find(".jpm-field-editor").length === 0
                    ) {
                      $actualColumn.remove();
                    }
                  }

                  // Ensure row has columns class if needed
                  if (!$targetRow.hasClass("jpm-row-has-columns")) {
                    $targetRow.addClass("jpm-row-has-columns");
                    // Wrap any existing fields in columns
                    $targetRow.find(".jpm-field-editor").each(function () {
                      var $field = $(this);
                      if (!$field.parent().hasClass("jpm-form-column")) {
                        $field.wrap("<div class='jpm-form-column'></div>");
                      }
                    });
                  }

                  // Wrap field in column if not already
                  if (!$fieldToMove.parent().hasClass("jpm-form-column")) {
                    $fieldToMove.wrap("<div class='jpm-form-column'></div>");
                  }

                  // Insert based on position (left = beginning, right = end)
                  if (isLeft) {
                    $fieldToMove.parent().prependTo($targetRow);
                  } else {
                    $fieldToMove.parent().appendTo($targetRow);
                  }

                  $fieldToMove.css("opacity", "1");
                }
              }
            } else {
              $draggedField.css("opacity", "1");
            }
            // Remove all position classes and labels
            $(".jpm-form-row, .jpm-form-column").removeClass(
              "jpm-drop-hover jpm-drop-top jpm-drop-bottom jpm-drop-left jpm-drop-right jpm-drop-middle"
            );

            reorganizeRows();
            updateFormFields();
            setTimeout(function () {
              initializeSortable();
            }, 100);
            return;
          }

          // Find and remove the original field from source row
          if ($sourceRow.length > 0 && fieldIndex) {
            var $originalField = $sourceRow.find(
              ".jpm-field-editor[data-index='" + fieldIndex + "']"
            );
            if ($originalField.length > 0) {
              $originalField.remove();
            }
          }

          // If dropped on a column, add to that column's row
          if ($targetColumn && $targetColumn.length > 0) {
            // Get mouse position relative to the column
            var colOffset = $targetColumn.offset();
            var colWidth = $targetColumn.width();
            var colHeight = $targetColumn.height();
            var mouseX = event.pageX - colOffset.left;
            var mouseY = event.pageY - colOffset.top;

            // Determine if dropped on top or bottom half
            var isTop = mouseY < colHeight / 2;
            // Determine if dropped on left or right half
            var isLeft = mouseX < colWidth / 2;

            // Calculate vertical threshold (top 30% or bottom 30% = new row, middle 40% = columns)
            var topThreshold = colHeight * 0.3;
            var bottomThreshold = colHeight * 0.7;
            var isInTopZone = mouseY < topThreshold;
            var isInBottomZone = mouseY > bottomThreshold;

            // If dropped in top or bottom zone, create new row above/below
            if (isInTopZone || isInBottomZone) {
              // Create new row
              var rowIndex = $targetRow.index();
              var $newRow = $(
                "<div class='jpm-form-row' data-row-index='" +
                  rowIndex +
                  "'></div>"
              );

              // Wrap dragged field if needed
              if ($draggedField.parent().hasClass("jpm-form-column")) {
                $draggedField.unwrap();
              }

              $draggedField.appendTo($newRow);

              // Insert row based on position
              if (isInTopZone) {
                $targetRow.before($newRow);
              } else {
                $targetRow.after($newRow);
              }

              // Check if original row now has only one field - make it full width
              var $remainingFields = $targetRow.find(".jpm-field-editor");
              var $remainingColumns = $targetRow.find(".jpm-form-column");

              if ($remainingFields.length === 1) {
                // Only one field left - remove columns and make it full width
                var $singleField = $remainingFields.first();
                var $singleColumn = $singleField.parent();

                if ($singleColumn.hasClass("jpm-form-column")) {
                  $singleField.unwrap();
                  $targetRow.removeClass("jpm-row-has-columns");
                }

                // Set to full width
                $singleField.find(".jpm-field-column-width").val(12);
                $singleField.find(".jpm-field-column-badge").text("Full");
              } else if ($remainingColumns.length > 0) {
                // Multiple fields remain - recalculate column widths
                // This will be handled by reorganizeRows()
              }

              $draggedField.css("opacity", "1");
            } else {
              // Middle zone - create columns for left/right positioning
              // Wrap dragged field in column if not already
              if (!$draggedField.parent().hasClass("jpm-form-column")) {
                $draggedField.wrap("<div class='jpm-form-column'></div>");
              }

              // Insert based on position
              if (isLeft) {
                $draggedField.parent().insertBefore($targetColumn);
              } else {
                $draggedField.parent().insertAfter($targetColumn);
              }

              // Ensure row has columns class
              $targetRow.addClass("jpm-row-has-columns");
              $draggedField.css("opacity", "1");
            }
          } else {
            // If target row has a field, check position
            var $existingField = $targetRow.find(".jpm-field-editor");
            if (
              $existingField.length > 0 &&
              !$existingField.is($draggedField)
            ) {
              // Get mouse position relative to the row
              var rowOffset = $targetRow.offset();
              var rowWidth = $targetRow.width();
              var rowHeight = $targetRow.height();
              var mouseX = event.pageX - rowOffset.left;
              var mouseY = event.pageY - rowOffset.top;

              // Determine if dropped on top or bottom half
              var isTop = mouseY < rowHeight / 2;
              // Determine if dropped on left or right half
              var isLeft = mouseX < rowWidth / 2;

              // Calculate vertical threshold (top 30% or bottom 30% = new row, middle 40% = columns)
              var topThreshold = rowHeight * 0.3;
              var bottomThreshold = rowHeight * 0.7;
              var isInTopZone = mouseY < topThreshold;
              var isInBottomZone = mouseY > bottomThreshold;

              // If dropped in top or bottom zone, create new row above/below
              if (isInTopZone || isInBottomZone) {
                // Create new row
                var rowIndex = $targetRow.index();
                var $newRow = $(
                  "<div class='jpm-form-row' data-row-index='" +
                    rowIndex +
                    "'></div>"
                );

                // Wrap dragged field if needed
                if ($draggedField.parent().hasClass("jpm-form-column")) {
                  $draggedField.unwrap();
                }

                $draggedField.appendTo($newRow);

                // Insert row based on position
                if (isInTopZone) {
                  $targetRow.before($newRow);
                } else {
                  $targetRow.after($newRow);
                }

                // Check if original row now has only one field - make it full width
                var $remainingFields = $targetRow.find(".jpm-field-editor");
                var $remainingColumns = $targetRow.find(".jpm-form-column");

                if ($remainingFields.length === 1) {
                  // Only one field left - remove columns and make it full width
                  var $singleField = $remainingFields.first();
                  var $singleColumn = $singleField.parent();

                  if ($singleColumn.hasClass("jpm-form-column")) {
                    $singleField.unwrap();
                    $targetRow.removeClass("jpm-row-has-columns");
                  }

                  // Set to full width
                  $singleField.find(".jpm-field-column-width").val(12);
                  $singleField.find(".jpm-field-column-badge").text("Full");
                } else if ($remainingColumns.length > 0) {
                  // Multiple fields remain - recalculate column widths
                  // This will be handled by reorganizeRows()
                }

                $draggedField.css("opacity", "1");
              } else {
                // Middle zone - create columns for left/right positioning
                // Create column wrapper if it doesn't exist
                if (!$targetRow.hasClass("jpm-row-has-columns")) {
                  $targetRow.addClass("jpm-row-has-columns");
                  $existingField.wrap("<div class='jpm-form-column'></div>");
                }

                // Wrap dragged field in column
                $draggedField.wrap("<div class='jpm-form-column'></div>");

                // Insert based on position
                if (isLeft) {
                  $draggedField.parent().insertBefore($existingField.parent());
                } else {
                  $draggedField.parent().insertAfter($existingField.parent());
                }

                $draggedField.css("opacity", "1");
              }
            } else {
              // Target row is empty, just add the field
              $draggedField.css("opacity", "1");
              $draggedField.appendTo($targetRow);
            }
          }

          // Clean up empty source row
          if ($sourceRow.length > 0 && !$sourceRow.is($targetRow)) {
            var $remainingFields = $sourceRow.find(".jpm-field-editor");
            if ($remainingFields.length === 0) {
              $sourceRow.remove();
            }
          }

          // Remove all position classes and labels from all rows and columns
          $(".jpm-form-row, .jpm-form-column").removeClass(
            "jpm-drop-hover jpm-drop-top jpm-drop-bottom jpm-drop-left jpm-drop-right jpm-drop-middle"
          );

          reorganizeRows();
          updateFormFields();

          // Reinitialize after DOM changes with a small delay
          setTimeout(function () {
            initializeSortable();
          }, 100);
        },
      });
    });
  }

  // Update form fields JSON
  function updateFormFields() {
    var fields = [];
    $(".jpm-form-row").each(function () {
      var $row = $(this);
      $row.find(".jpm-field-editor").each(function () {
        var $field = $(this);
        var columnWidth = $field.find(".jpm-field-column-width").val() || "12";
        var field = {
          type: $field.find(".jpm-field-type").val() || "text",
          label: $field.find(".jpm-field-label").val() || "",
          name: $field.find(".jpm-field-name").val() || "",
          required: $field.find(".jpm-field-required").is(":checked"),
          placeholder: $field.find(".jpm-field-placeholder").val() || "",
          options: $field.find(".jpm-field-options").val() || "",
          description: $field.find(".jpm-field-description").val() || "",
          column_width: columnWidth,
        };
        fields.push(field);
      });
    });
    var jsonData = JSON.stringify(fields);
    var $hiddenField = $("#jpm-form-fields-json");
    if ($hiddenField.length > 0) {
      $hiddenField.val(jsonData);
      // Trigger multiple events to ensure form knows the value changed
      $hiddenField.trigger("change").trigger("input");
    } else {
      console.error(
        "Form fields JSON input not found! Cannot save form fields."
      );
      // Try to find it again after a short delay
      setTimeout(function () {
        var $retryField = $("#jpm-form-fields-json");
        if ($retryField.length > 0) {
          $retryField.val(jsonData);
          $retryField.trigger("change").trigger("input");
        } else {
          // Last resort: try to create the field
          var $formBuilder = $("#jpm-form-fields-container").closest(
            ".jpm-form-builder"
          );
          if ($formBuilder.length > 0) {
            var $newField = $(
              '<input type="hidden" name="jpm_form_fields_json" id="jpm-form-fields-json" value="">'
            );
            $formBuilder.append($newField);
            $newField.val(jsonData);
            $newField.trigger("change").trigger("input");
          }
        }
      }, 100);
    }
  }

  // Initialize on page load
  reorganizeRows();
  initializeSortable();
  updateFormFields();

  // Reorganize after a short delay to ensure DOM is ready
  setTimeout(function () {
    reorganizeRows();
    initializeSortable();
    updateFormFields(); // Ensure fields are updated after DOM is ready
  }, 100);

  // Update form fields before form submission (publish/update)
  $("#post").on("submit", function (e) {
    // Update fields synchronously before form submits - don't allow form to submit until done
    updateFormFields();

    // Ensure the hidden field is in the DOM and has data
    var $hiddenField = $("#jpm-form-fields-json");
    if ($hiddenField.length === 0) {
      console.error("Form fields JSON input not found!");
      // Try to create it if it doesn't exist
      var $formBuilder = $("#jpm-form-fields-container").closest(
        ".jpm-form-builder"
      );
      if ($formBuilder.length > 0) {
        $formBuilder.append(
          '<input type="hidden" name="jpm_form_fields_json" id="jpm-form-fields-json" value="">'
        );
        updateFormFields();
      }
    } else {
      var jsonValue = $hiddenField.val();
      if (!jsonValue || jsonValue === "[]" || jsonValue === "") {
        console.warn("Form fields JSON is empty! Attempting to update...");
        updateFormFields();
        // Double check after update
        jsonValue = $hiddenField.val();
        if (!jsonValue || jsonValue === "[]" || jsonValue === "") {
          console.error("Form fields JSON is still empty after update!");
        }
      }
    }
  });

  // Also update on publish/update button clicks (before submit) - use mousedown to catch it early
  $(document).on(
    "mousedown",
    "#publish, #save-post, #save, input[name='save']",
    function () {
      updateFormFields();
    }
  );

  // Also update on click as backup
  $(document).on(
    "click",
    "#publish, #save-post, #save, input[name='save']",
    function () {
      updateFormFields();
    }
  );

  // Update on autosave heartbeat
  if (typeof wp !== "undefined" && wp.heartbeat) {
    $(document).on("heartbeat-send", function () {
      updateFormFields();
    });
  }

  // Update on any field property change with a debounce
  var updateTimeout;
  $(document).on(
    "input change",
    ".jpm-field-label, .jpm-field-name, .jpm-field-placeholder, .jpm-field-options, .jpm-field-description, .jpm-field-required, .jpm-field-type",
    function () {
      clearTimeout(updateTimeout);
      updateTimeout = setTimeout(function () {
        updateFormFields();
      }, 300);
    }
  );
});
