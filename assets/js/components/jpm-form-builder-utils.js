/**
 * Form Builder Utilities
 * Helper functions for form builder operations
 */
(function ($) {
  "use strict";

  /**
   * Calculate column width based on number of fields in row
   * @param {number} fieldCount - Number of fields in the row
   * @returns {number} Column width (12, 6, or 4)
   */
  window.JPMFormBuilderUtils = {
    calculateColumnWidth: function (fieldCount) {
      if (fieldCount === 1) return 12;
      if (fieldCount === 2) return 6;
      if (fieldCount === 3) return 4;
      return 12; // Default to full width
    },

    /**
     * Get column width text for display
     * @param {number} width - Column width value
     * @returns {string} Display text
     */
    getColumnWidthText: function (width) {
      return width === 12 ? "Full" : width + " cols";
    },

    /**
     * Sanitize field name from label
     * @param {string} label - Field label
     * @returns {string} Sanitized field name
     */
    sanitizeFieldName: function (label) {
      return label
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, "_")
        .replace(/^_+|_+$/g, "");
    },
  };
})(jQuery);
