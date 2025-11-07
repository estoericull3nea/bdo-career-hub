/**
 * Form Builder Drag & Drop System
 * Handles all drag and drop functionality with enhanced visual feedback
 */
(function ($) {
  "use strict";

  window.JPMFormBuilderDragDrop = {
    /**
     * Initialize enhanced sortable with better drag and drop
     */
    initializeSortable: function () {
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
        var self = this;

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
            window.JPMFormBuilderDragDrop.updateDropZoneHighlights(event, ui);
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
            var dropInfo = window.JPMFormBuilderDragDrop.calculateDropPosition(
              event,
              $(this),
              ui.draggable
            );
            window.JPMFormBuilderDragDrop.showEnhancedDropIndicator(
              dropInfo,
              $(this)
            );
            $(this).addClass("jpm-drop-hover");
          },
          out: function (event, ui) {
            $(this).removeClass(
              "jpm-drop-hover jpm-drop-top jpm-drop-bottom jpm-drop-left jpm-drop-right jpm-drop-middle"
            );
            window.JPMFormBuilderDragDrop.removeDropIndicator($(this));
          },
          drop: function (event, ui) {
            window.JPMFormBuilderDragDrop.handleEnhancedDrop(
              event,
              ui,
              $(this)
            );
          },
        });
      });
    },

    /**
     * Calculate precise drop position with visual zones
     * @param {Event} event - Drop event
     * @param {jQuery} $target - Target element
     * @param {jQuery} $draggedField - Dragged field element
     * @returns {Object} Drop zone information
     */
    calculateDropPosition: function (event, $target, $draggedField) {
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
    },

    /**
     * Show enhanced visual drop indicator with animations
     * @param {Object} dropInfo - Drop zone information
     * @param {jQuery} $target - Target element
     */
    showEnhancedDropIndicator: function (dropInfo, $target) {
      // Remove any existing indicators first
      this.removeDropIndicator($target);

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
    },

    /**
     * Remove drop indicators with fade out
     * @param {jQuery} $target - Target element
     */
    removeDropIndicator: function ($target) {
      var $indicators = $target
        .find(".jpm-drop-indicator")
        .add($target.siblings(".jpm-drop-indicator"));

      $indicators.removeClass("jpm-drop-indicator-visible");
      setTimeout(function () {
        $indicators.remove();
      }, 200);
    },

    /**
     * Update drop zone highlights during drag
     * @param {Event} event - Drag event
     * @param {Object} ui - jQuery UI helper object
     */
    updateDropZoneHighlights: function (event, ui) {
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
    },

    /**
     * Enhanced drop handler with smooth animations
     * @param {Event} event - Drop event
     * @param {Object} ui - jQuery UI helper object
     * @param {jQuery} $target - Target element
     */
    handleEnhancedDrop: function (event, ui, $target) {
      event.stopPropagation();
      event.preventDefault();

      var $draggedField = ui.draggable;
      var fieldIndex =
        ui.helper.data("field-index") || $draggedField.attr("data-index");
      var $sourceRow =
        ui.helper.data("original-row") || $draggedField.data("original-row");
      var dropInfo = this.calculateDropPosition(event, $target, $draggedField);

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

      var self = this;
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
          if (window.JPMFormBuilderLayout) {
            window.JPMFormBuilderLayout.createNewRowWithField(
              $actualField,
              $targetRow,
              "before"
            );
          }
        } else if (dropInfo.position === "after") {
          // Create new row BELOW target
          if (window.JPMFormBuilderLayout) {
            window.JPMFormBuilderLayout.createNewRowWithField(
              $actualField,
              $targetRow,
              "after"
            );
          }
        } else {
          // Middle position - add to columns
          if (window.JPMFormBuilderLayout) {
            window.JPMFormBuilderLayout.addFieldToRowAsColumn(
              $actualField,
              $targetRow,
              dropInfo.side
            );
          }
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
        if (window.JPMFormBuilderLayout) {
          window.JPMFormBuilderLayout.cleanupSourceRow($sourceRow, $targetRow);
        }

        // Remove all visual indicators
        $(".jpm-drop-indicator").remove();
        $(".jpm-form-row, .jpm-form-column").removeClass(
          "jpm-drop-hover jpm-drop-active jpm-drop-target jpm-drop-nearby " +
            "jpm-drop-top jpm-drop-bottom jpm-drop-left jpm-drop-right jpm-drop-middle"
        );

        // Update field data and reorganize
        if (window.JPMFormBuilderLayout) {
          window.JPMFormBuilderLayout.reorganizeRows();
        }
        if (window.JPMFormBuilderPersistence) {
          window.JPMFormBuilderPersistence.updateFormFields();
        }

        // Reinitialize drag and drop with delay
        setTimeout(function () {
          window.JPMFormBuilderDragDrop.initializeSortable();
        }, 200);
      }, 150);
    },
  };
})(jQuery);
