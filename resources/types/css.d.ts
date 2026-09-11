/*
 * Ambient declaration for stylesheet imports.
 *
 * Kept in its own file, without any top level import or export: a wildcard
 * module declaration only applies when the containing file is a global script.
 */

declare module '*.css';
