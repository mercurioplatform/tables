<template data-tables-cell-edit-template="popover"><div class="tables-cell-popover" role="dialog"><form data-tables-cell-edit-form><div class="d-flex justify-content-end gap-2 mt-2"><button type="button" class="btn btn-sm btn-link" data-tables-cell-edit-cancel>{{ __('tables::cell.cancel') }}</button><button type="submit" class="btn btn-sm btn-primary" data-tables-cell-edit-save>{{ __('tables::cell.save') }}</button></div></form></div></template>

<template data-tables-cell-edit-template="input-text"><input type="text" class="form-control form-control-sm" data-tables-cell-edit-input></template>

<template data-tables-cell-edit-template="input-number"><input type="number" class="form-control form-control-sm" data-tables-cell-edit-input></template>

<template data-tables-cell-edit-template="input-select"><select class="form-select form-select-sm" data-tables-cell-edit-input></select></template>

<template data-tables-cell-edit-template="input-select-option"><option></option></template>

<template data-tables-cell-edit-template="input-boolean"><div class="d-flex flex-column gap-1" data-tables-cell-edit-input></div></template>

<template data-tables-cell-edit-template="input-boolean-row"><div class="form-check"><input type="radio" class="form-check-input"><label class="form-check-label"></label></div></template>

<template data-tables-cell-edit-template="invalid-feedback"><div class="invalid-feedback d-block" data-tables-cell-edit-error></div></template>
