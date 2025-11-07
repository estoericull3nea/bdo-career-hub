/**
 * Form Builder Core
 * Main initialization and coordination of all components
 */
(function ($) {
  "use strict";

  window.JPMFormBuilderCore = {
    /**
     * Initialize the form builder system
     */
    init: function () {
      // Initialize components in order
      if (window.JPMFormBuilderUtils) {
        // Utils are already loaded (no init needed)
      }

      if (window.JPMFormBuilderFields) {
        window.JPMFormBuilderFields.init();
      }

      if (window.JPMFormBuilderLayout) {
        window.JPMFormBuilderLayout.reorganizeRows();
      }

      if (window.JPMFormBuilderPersistence) {
        window.JPMFormBuilderPersistence.init();
        window.JPMFormBuilderPersistence.updateFormFields();
      }

      if (window.JPMFormBuilderDragDrop) {
        window.JPMFormBuilderDragDrop.initializeSortable();
      }

      // Reorganize after a short delay to ensure DOM is ready
      setTimeout(function () {
        if (window.JPMFormBuilderLayout) {
          window.JPMFormBuilderLayout.reorganizeRows();
        }
        if (window.JPMFormBuilderDragDrop) {
          window.JPMFormBuilderDragDrop.initializeSortable();
        }
        if (window.JPMFormBuilderPersistence) {
          window.JPMFormBuilderPersistence.updateFormFields();
        }
      }, 100);
    },
  };

  // Initialize when DOM is ready
  $(document).ready(function () {
    window.JPMFormBuilderCore.init();
  });
})(jQuery);
