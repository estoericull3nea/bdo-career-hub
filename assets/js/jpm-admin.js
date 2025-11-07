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

  // Initialize enhanced sortable with better drag and drop
  function initializeSortable() {
    if (!$.fn.draggable || !$.fn.droppable) return;

    // Destroy existing instances safely
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

    // Make each field individually draggable with enhanced features
    $(".jpm-field-editor").each(function () {
      var $field = $(this);

      $field.draggable({
        handle: ".jpm-field-handle, .jpm-field-header",
        cursor: "move",
        revert: "invalid",
        revertDuration: 200,
        helper: function () {
          // Create enhanced visual helper
          var $helper = $(this).clone();
          $helper.css({
            width: $(this).width(),
            opacity: 0.85,
            zIndex: 10000,
            boxShadow: "0 8px 20px rgba(0,0,0,0.25)",
            transform: "rotate(-2deg) scale(1.02)",
            border: "2px solid #2271b1",
          });
          $helper.addClass("jpm-drag-helper");
          return $helper;
        },
        appendTo: "body",
        scroll: true,
        scrollSensitivity: 40,
        scrollSpeed: 15,
        distance: 5, // Minimum distance before drag starts
        start: function (event, ui) {
          var $originalParent = $field.parent();
          var $originalRow = $field.closest(".jpm-form-row");

          // Store original position data
          $field.data("original-parent", $originalParent);
          $field.data("original-row", $originalRow);
          $field.data("original-index", $field.index());

          // Visual feedback - make original semi-transparent
          $field.css({
            opacity: "0.25",
            transition: "opacity 0.2s ease",
          });

          // Add global dragging state
          $("body").addClass("jpm-dragging");
          $("#jpm-form-builder").addClass("jpm-drag-active");

          // Mark helper with data
          ui.helper.data("dropped", false);
          ui.helper.data("original-row", $originalRow);
          ui.helper.data("field-index", $field.attr("data-index"));

          // Show all drop zones
          $(".jpm-form-row, .jpm-form-column").addClass(
            "jpm-drop-zone-visible"
          );
        },
        drag: function (event, ui) {
          // Real-time drop zone highlighting
          updateDropZoneHighlights(event, ui);
        },
        stop: function (event, ui) {
          // Clean up dragging state
          $("body").removeClass("jpm-dragging");
          $("#jpm-form-builder").removeClass("jpm-drag-active");

          // Restore opacity if not successfully dropped
          if (!ui.helper || !ui.helper.data("dropped")) {
            $field.css({
              opacity: "1",
              transition: "opacity 0.3s ease",
            });
          }

          // Remove all drop indicators and highlights
          $(".jpm-drop-indicator").remove();
          $(".jpm-form-row, .jpm-form-column").removeClass(
            "jpm-drop-hover jpm-drop-active jpm-drop-target " +
              "jpm-drop-zone-visible jpm-drop-top jpm-drop-bottom " +
              "jpm-drop-left jpm-drop-right jpm-drop-middle"
          );
        },
      });
    });

    // Enhanced droppable zones with precise positioning
    $(".jpm-form-row, .jpm-form-column").each(function () {
      var $dropZone = $(this);

      $dropZone.droppable({
        accept: ".jpm-field-editor",
        tolerance: "pointer",
        greedy: true,
        activeClass: "jpm-drop-active",
        over: function (event, ui) {
          var dropInfo = calculateDropPosition(event, $(this), ui.draggable);
          showEnhancedDropIndicator(dropInfo, $(this));
          $(this).addClass("jpm-drop-hover");
        },
        out: function (event, ui) {
          $(this).removeClass(
            "jpm-drop-hover jpm-drop-top jpm-drop-bottom jpm-drop-left jpm-drop-right jpm-drop-middle"
          );
          removeDropIndicator($(this));
        },
        drop: function (event, ui) {
          handleEnhancedDrop(event, ui, $(this));
        },
      });
    });
  }

  // Calculate precise drop position with visual zones
  function calculateDropPosition(event, $target, $draggedField) {
    var isRow = $target.hasClass("jpm-form-row");
    var isColumn = $target.hasClass("jpm-form-column");

    var offset = $target.offset();
    var width = $target.outerWidth();
    var height = $target.outerHeight();
    var mouseX = event.pageX - offset.left;
    var mouseY = event.pageY - offset.top;

    // Calculate relative positions (0-1)
    var relX = mouseX / width;
    var relY = mouseY / height;

    // Define zone thresholds (adjustable for sensitivity)
    var TOP_ZONE = 0.25; // Top 25% = new row above
    var BOTTOM_ZONE = 0.75; // Bottom 25% = new row below
    var LEFT_ZONE = 0.3; // Left 30% = left column
    var RIGHT_ZONE = 0.7; // Right 30% = right column

    var zone = {
      target: $target,
      isRow: isRow,
      isColumn: isColumn,
      position: "middle",
      side: "center",
      relX: relX,
      relY: relY,
    };

    // Determine vertical position
    if (relY < TOP_ZONE) {
      zone.position = "before";
      zone.description = "New row above";
    } else if (relY > BOTTOM_ZONE) {
      zone.position = "after";
      zone.description = "New row below";
    } else {
      zone.position = "middle";

      // Determine horizontal side for column layout
      if (relX < LEFT_ZONE) {
        zone.side = "left";
        zone.description = "Add to left column";
      } else if (relX > RIGHT_ZONE) {
        zone.side = "right";
        zone.description = "Add to right column";
      } else {
        zone.side = "center";
        zone.description = "Add to center";
      }
    }

    return zone;
  }

  // Show enhanced visual drop indicator with animations
  function showEnhancedDropIndicator(dropInfo, $target) {
    // Remove any existing indicators first
    removeDropIndicator($target);

    var position = dropInfo.position;
    var side = dropInfo.side;

    // Remove old position classes
    $target.removeClass(
      "jpm-drop-top jpm-drop-bottom jpm-drop-left jpm-drop-right jpm-drop-middle"
    );

    // Create animated indicator
    var $indicator = $('<div class="jpm-drop-indicator"></div>');

    if (position === "before") {
      $indicator.addClass("jpm-drop-indicator-top");
      $target.addClass("jpm-drop-top");
      $target.before($indicator);
    } else if (position === "after") {
      $indicator.addClass("jpm-drop-indicator-bottom");
      $target.addClass("jpm-drop-bottom");
      $target.after($indicator);
    } else if (position === "middle") {
      $target.addClass("jpm-drop-middle");

      if (side === "left") {
        $indicator.addClass("jpm-drop-indicator-left");
        $target.addClass("jpm-drop-left");
      } else if (side === "right") {
        $indicator.addClass("jpm-drop-indicator-right");
        $target.addClass("jpm-drop-right");
      } else {
        $indicator.addClass("jpm-drop-indicator-center");
      }

      $target.append($indicator);
    }

    // Animate indicator appearance
    setTimeout(function () {
      $indicator.addClass("jpm-drop-indicator-visible");
    }, 10);
  }

  // Remove drop indicators with fade out
  function removeDropIndicator($target) {
    var $indicators = $target
      .find(".jpm-drop-indicator")
      .add($target.siblings(".jpm-drop-indicator"));

    $indicators.removeClass("jpm-drop-indicator-visible");
    setTimeout(function () {
      $indicators.remove();
    }, 200);
  }

  // Update drop zone highlights during drag
  function updateDropZoneHighlights(event, ui) {
    var $draggedField = ui.helper;
    var threshold = 50; // Proximity threshold in pixels

    $(".jpm-form-row, .jpm-form-column").each(function () {
      var $zone = $(this);
      var offset = $zone.offset();
      var width = $zone.outerWidth();
      var height = $zone.outerHeight();

      // Check if mouse is within or near the zone
      var isNear =
        event.pageX >= offset.left - threshold &&
        event.pageX <= offset.left + width + threshold &&
        event.pageY >= offset.top - threshold &&
        event.pageY <= offset.top + height + threshold;

      var isOver =
        event.pageX >= offset.left &&
        event.pageX <= offset.left + width &&
        event.pageY >= offset.top &&
        event.pageY <= offset.top + height;

      if (isOver) {
        $zone.addClass("jpm-drop-target");
      } else if (isNear) {
        $zone.addClass("jpm-drop-nearby");
      } else {
        $zone.removeClass("jpm-drop-target jpm-drop-nearby");
      }
    });
  }

  // Enhanced drop handler with smooth animations
  function handleEnhancedDrop(event, ui, $target) {
    event.stopPropagation();
    event.preventDefault();

    var $draggedField = ui.draggable;
    var fieldIndex =
      ui.helper.data("field-index") || $draggedField.attr("data-index");
    var $sourceRow =
      ui.helper.data("original-row") || $draggedField.data("original-row");
    var dropInfo = calculateDropPosition(event, $target, $draggedField);

    // Mark as successfully dropped
    if (ui.helper) {
      ui.helper.data("dropped", true);
    }

    // Find the actual field in DOM (not the helper)
    var $actualField = $(
      ".jpm-field-editor[data-index='" + fieldIndex + "']"
    ).first();

    if ($actualField.length === 0) {
      $actualField = $draggedField;
    }

    // Store original parent for cleanup
    var $originalColumn = $actualField.parent();
    var wasInColumn = $originalColumn.hasClass("jpm-form-column");

    // Fade out from original position
    $actualField.css({
      opacity: "0",
      transform: "scale(0.95)",
    });

    setTimeout(function () {
      // Detach from original position
      $actualField.detach();

      // Clean up empty column
      if (
        wasInColumn &&
        $originalColumn.find(".jpm-field-editor").length === 0
      ) {
        $originalColumn.fadeOut(200, function () {
          $(this).remove();
        });
      }

      // Execute drop based on calculated position
      var $targetRow = dropInfo.isRow
        ? $target
        : $target.closest(".jpm-form-row");

      if (dropInfo.position === "before") {
        // Create new row ABOVE target
        createNewRowWithField($actualField, $targetRow, "before");
      } else if (dropInfo.position === "after") {
        // Create new row BELOW target
        createNewRowWithField($actualField, $targetRow, "after");
      } else {
        // Middle position - add to columns
        addFieldToRowAsColumn($actualField, $targetRow, dropInfo.side);
      }

      // Animate field appearance in new position
      $actualField.css({
        opacity: "0",
        transform: "scale(0.95)",
      });

      setTimeout(function () {
        $actualField.css({
          opacity: "1",
          transform: "scale(1)",
          transition: "all 0.3s ease",
        });
      }, 50);

      // Clean up source row if empty or single field
      cleanupSourceRow($sourceRow, $targetRow);

      // Remove all visual indicators
      $(".jpm-drop-indicator").remove();
      $(".jpm-form-row, .jpm-form-column").removeClass(
        "jpm-drop-hover jpm-drop-active jpm-drop-target jpm-drop-nearby " +
          "jpm-drop-top jpm-drop-bottom jpm-drop-left jpm-drop-right jpm-drop-middle"
      );

      // Update field data and reorganize
      reorganizeRows();
      updateFormFields();

      // Reinitialize drag and drop with delay
      setTimeout(function () {
        initializeSortable();
      }, 200);
    }, 150);
  }

  // Helper: Create new row with field
  function createNewRowWithField($field, $targetRow, position) {
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
  }

  // Helper: Add field to row as column
  function addFieldToRowAsColumn($field, $targetRow, side) {
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
      createNewRowWithField($field, $targetRow, "after");
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
  }

  // Helper: Clean up source row after field is moved
  function cleanupSourceRow($sourceRow, $targetRow) {
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
