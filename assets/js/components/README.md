# Form Builder Components

This directory contains modular components for the Job Posting Manager Form Builder functionality.

## Component Structure

The form builder has been split into separate, maintainable components:

### 1. `jpm-form-builder-utils.js`

**Purpose**: Utility functions and helper methods  
**Dependencies**: jQuery  
**Exports**: `window.JPMFormBuilderUtils`

- `calculateColumnWidth(fieldCount)` - Calculates column width based on field count
- `getColumnWidthText(width)` - Gets display text for column width
- `sanitizeFieldName(label)` - Sanitizes field name from label

### 2. `jpm-form-builder-fields.js`

**Purpose**: Field management (add, remove, toggle, update)  
**Dependencies**: jQuery, Utils  
**Exports**: `window.JPMFormBuilderFields`

- `init()` - Initializes field management
- `addField(fieldType)` - Adds new field via AJAX
- `removeField($fieldEditor)` - Removes field and cleans up layout
- Event handlers for field interactions

### 3. `jpm-form-builder-layout.js`

**Purpose**: Row and column organization  
**Dependencies**: jQuery, Utils  
**Exports**: `window.JPMFormBuilderLayout`

- `reorganizeRows()` - Reorganizes rows and updates column widths
- `cleanupSourceRow($sourceRow, $targetRow)` - Cleans up source row after field move
- `createNewRowWithField($field, $targetRow, position)` - Creates new row with field
- `addFieldToRowAsColumn($field, $targetRow, side)` - Adds field to row as column

### 4. `jpm-form-builder-dragdrop.js`

**Purpose**: Drag and drop functionality with visual feedback  
**Dependencies**: jQuery, jQuery UI (draggable, droppable), Utils, Layout  
**Exports**: `window.JPMFormBuilderDragDrop`

- `initializeSortable()` - Initializes drag and drop system
- `calculateDropPosition(event, $target, $draggedField)` - Calculates drop position
- `showEnhancedDropIndicator(dropInfo, $target)` - Shows visual drop indicators
- `removeDropIndicator($target)` - Removes drop indicators
- `updateDropZoneHighlights(event, ui)` - Updates drop zone highlights
- `handleEnhancedDrop(event, ui, $target)` - Handles drop events

### 5. `jpm-form-builder-persistence.js`

**Purpose**: Form fields data persistence and saving  
**Dependencies**: jQuery  
**Exports**: `window.JPMFormBuilderPersistence`

- `init()` - Initializes persistence handlers
- `updateFormFields()` - Updates form fields JSON
- Event handlers for form submission, autosave, etc.

### 6. `jpm-form-builder-core.js`

**Purpose**: Main initialization and coordination  
**Dependencies**: All other components  
**Exports**: `window.JPMFormBuilderCore`

- `init()` - Initializes all components in correct order

## Loading Order

Components are loaded in this order (via WordPress `wp_enqueue_script`):

1. **Utils** - Base utilities (no dependencies)
2. **Fields** - Field management (depends on Utils)
3. **Layout** - Layout management (depends on Utils)
4. **DragDrop** - Drag and drop (depends on Utils, Layout)
5. **Persistence** - Data persistence (no dependencies)
6. **Core** - Main initialization (depends on all)
7. **Main Admin** - Main admin script (depends on Core)

## Usage

All components are automatically initialized when the page loads. The core component (`jpm-form-builder-core.js`) handles initialization of all other components.

## Benefits of This Structure

1. **Maintainability**: Each component has a single, clear responsibility
2. **Scalability**: Easy to add new features or modify existing ones
3. **Testability**: Components can be tested independently
4. **Reusability**: Components can be reused in other contexts
5. **Debugging**: Easier to locate and fix issues in specific components
6. **Code Organization**: Related functionality is grouped together

## Adding New Features

When adding new features:

1. Determine which component the feature belongs to
2. Add the functionality to the appropriate component
3. If needed, create a new component for unrelated functionality
4. Update dependencies in `job-posting-manager.php` if needed
5. Update this README with new component information
