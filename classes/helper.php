<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * File containing the helper class.
 *
 * @package    enrol_database
 * @copyright  2020 Hellen Lazari
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/cache/lib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Class containing a set of helpers.
 *
 * @package    enrol_database
 * @copyright  2020 Hellen Lazari
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_database_helper {

    /**
     * Return the available course formats.
     *
     * @return array
     */
    public static function get_course_formats() {
        return array_keys(core_component::get_plugin_list('format'));
    }

    /**
     * Extract enrolment data from passed data.
     *
     * Constructs an array of methods, and their options:
     * array(
     *     'method1' => array(
     *         'option1' => value,
     *         'option2' => value
     *     ),
     *     'method2' => array(
     *         'option1' => value,
     *         'option2' => value
     *     )
     * )
     *
     * @param array $data data to extract the enrolment data from.
     * @return array
     * @throws coding_exception
     */
    public static function get_enrolment_data($data) {
        $enroloptions = array();
        foreach ($data as $field => $value) {

            // Enrolmnent data.
            $matches = array();
            if (preg_match('/^enrolment_(\d+)(_(.+))?$/', $field, $matches)) {
                $key = $matches[1];
                if (!isset($enroloptions[$key])) {
                    $enroloptions[$key] = array();
                }
                if (empty($matches[3])) {
                    $enrolmethods[$key] = $value;
                } else {
                    $enroloptions[$key][$matches[3]] = $value;
                }
            }
        }

        // Combining enrolment methods and their options in a single array.
        $enrolmentdata = array();
        if (!empty($enrolmethods)) {
            $enrolmentplugins = self::get_enrolment_plugins();
            foreach ($enrolmethods as $key => $method) {
                if (!array_key_exists($method, $enrolmentplugins)) {
                    // Error!
                    continue;
                }
                $enrolmentdata[$enrolmethods[$key]] = $enroloptions[$key];
            }
        }
        return $enrolmentdata;
    }

    /**
     * Return the enrolment plugins.
     *
     * The result is cached for faster execution.
     *
     * @return array
     * @throws coding_exception
     * @throws coding_exception
     */
    public static function get_enrolment_plugins() {
        $cache = cache::make('enrol_database', 'helper');
        if (($enrol = $cache->get('enrol')) === false) {
            $enrol = enrol_get_plugins(false);
            $cache->set('enrol', $enrol);
        }
        return $enrol;
    }

    /**
     * Get the restore content tempdir.
     *
     * The tempdir is the sub directory in which the backup has been extracted.
     *
     * This caches the result for better performance, but $CFG->keeptempdirectoriesonbackup
     * needs to be enabled, otherwise the cache is ignored.
     *
     * @param string $backupfile path to a backup file.
     * @param string $shortname shortname of a course.
     * @param array $errors will be populated with errors found.
     * @return string|false false when the backup couldn't retrieved.
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public static function get_restore_content_dir($backupfile = null, $shortname = null, &$errors = array()) {
        global $CFG, $DB, $USER;

        $cachekey = null;
        if (!empty($backupfile)) {
            $backupfile = realpath($backupfile);
            if (empty($backupfile) || !is_readable($backupfile)) {
                $errors['cannotreadbackupfile'] = new lang_string('cannotreadbackupfile', 'enrol_database');
                return false;
            }
            $cachekey = 'backup_path:' . $backupfile;
        } else if (!empty($shortname) || is_numeric($shortname)) {
            $cachekey = 'backup_sn:' . $shortname;
        }

        if (empty($cachekey)) {
            return false;
        }

        // If $CFG->keeptempdirectoriesonbackup is not set to true, any restore happening would
        // automatically delete the backup directory... causing the cache to return an unexisting directory.
        $usecache = !empty($CFG->keeptempdirectoriesonbackup);
        if ($usecache) {
            $cache = cache::make('enrol_database', 'helper');
        }

        // If we don't use the cache, or if we do and not set, or the directory doesn't exist any more.
        if (!$usecache || (($backupid = $cache->get($cachekey)) === false || !is_dir(get_backup_temp_directory($backupid)))) {

            // Use null instead of false because it would consider that the cache key has not been set.
            $backupid = null;

            if (!empty($backupfile)) {
                // Extracting the backup file.
                $packer = get_file_packer('application/vnd.moodle.backup');
                $backupid = restore_controller::get_tempdir_name(SITEID, $USER->id);
                $path = make_backup_temp_directory($backupid, false);
                $result = $packer->extract_to_pathname($backupfile, $path);
                if (!$result) {
                    $errors['invalidbackupfile'] = new lang_string('invalidbackupfile', 'enrol_database');
                }
            } else if (!empty($shortname) || is_numeric($shortname)) {
                // Creating restore from an existing course.
                $courseid = $DB->get_field('course', 'id', array('shortname' => $shortname), IGNORE_MISSING);
                if (!empty($courseid)) {
                    $bc = new backup_controller(backup::TYPE_1COURSE, $courseid, backup::FORMAT_MOODLE,
                        backup::INTERACTIVE_NO, backup::MODE_IMPORT, $USER->id);
                    $bc->execute_plan();
                    $backupid = $bc->get_backupid();
                    $bc->destroy();
                } else {
                    $errors['coursetorestorefromdoesnotexist'] =
                        new lang_string('coursetorestorefromdoesnotexist', 'enrol_database');
                }
            }

            if ($usecache) {
                $cache->set($cachekey, $backupid);
            }
        }

        if ($backupid === null) {
            $backupid = false;
        }
        return $backupid;
    }

    /**
     * Return the role IDs.
     *
     * The result is cached for faster execution.
     *
     * @return array
     * @throws coding_exception
     * @throws coding_exception
     */
    public static function get_role_ids() {
        $cache = cache::make('enrol_database', 'helper');
        if (($roles = $cache->get('roles')) === false) {
            $roles = array();
            $rolesraw = get_all_roles();
            foreach ($rolesraw as $role) {
                $roles[$role->shortname] = $role->id;
            }
            $cache->set('roles', $roles);
        }
        return $roles;
    }

    /**
     * Helper to detect how many sections a course with a given shortname has.
     *
     * @param string $shortname shortname of a course to count sections from.
     * @return integer count of sections.
     * @throws dml_exception
     * @throws dml_exception
     */
    public static function get_coursesection_count($shortname) {
        global $DB;
        if (!empty($shortname) || is_numeric($shortname)) {
            // Creating restore from an existing course.
            $course = $DB->get_record('course', array('shortname' => $shortname));
        }
        if (!empty($course)) {
            $courseformat = course_get_format($course);
            return $courseformat->get_last_section_number();
        }
        return 0;
    }

    /**
     * Get the role renaming data from the passed data.
     *
     * @param array $data data to extract the names from.
     * @param array $errors will be populated with errors found.
     * @return array where the key is the role_<id>, the value is the new name.
     * @throws coding_exception
     * @throws coding_exception
     */
    public static function get_role_names($data, &$errors = array()) {
        $rolenames = array();
        $rolesids = self::get_role_ids();
        $invalidroles = array();
        foreach ($data as $field => $value) {

            $matches = array();
            if (preg_match('/^role_(.+)?$/', $field, $matches)) {
                if (!isset($rolesids[$matches[1]])) {
                    $invalidroles[] = $matches[1];
                    continue;
                }
                $rolenames['role_' . $rolesids[$matches[1]]] = $value;
            }

        }

        if (!empty($invalidroles)) {
            $errors['invalidroles'] = new lang_string('invalidroles', 'enrol_database', implode(', ', $invalidroles));
        }

        // Roles names.
        return $rolenames;
    }

    /**
     * Resolve a category based on the data passed.
     *
     * Key accepted are:
     * - category, which is supposed to be a category ID.
     * - category_idnumber
     * - category_path, array of categories from parent to child.
     *
     * @param array $data to resolve the category from.
     * @param array $errors will be populated with errors found.
     * @return int category ID.
     * @throws moodle_exception
     * @throws moodle_exception
     */
    public static function resolve_category($data, &$errors = array()) {
        $catid = null;

        if (!empty($data['category'])) {
            $category = core_course_category::get((int) $data['category'], IGNORE_MISSING);
            if (!empty($category) && !empty($category->id)) {
                $catid = $category->id;
            } else {
                $errors['couldnotresolvecatgorybyid'] =
                    new lang_string('couldnotresolvecatgorybyid', 'enrol_database');
            }
        }

        if (empty($catid) && !empty($data['category_idnumber'])) {
            $catid = self::resolve_category_by_idnumber($data['category_idnumber']);
            if (empty($catid)) {
                $errors['couldnotresolvecatgorybyidnumber'] =
                    new lang_string('couldnotresolvecatgorybyidnumber', 'enrol_database');
            }
        }
        if (empty($catid) && !empty($data['category_path'])) {
            $catid = self::resolve_category_by_path(explode(' / ', $data['category_path']));
            if (empty($catid)) {
                $errors['couldnotresolvecatgorybypath'] =
                    new lang_string('couldnotresolvecatgorybypath', 'enrol_database');
            }
        }

        return $catid;
    }

    /**
     * Resolve a category by ID number.
     *
     * @param string $idnumber category ID number.
     * @return int category ID.
     * @throws coding_exception
     * @throws dml_exception
     */
    public static function resolve_category_by_idnumber($idnumber) {
        global $DB;
        $cache = cache::make('enrol_database', 'helper');
        $cachekey = 'cat_idn_' . $idnumber;
        if (($id = $cache->get($cachekey)) === false) {
            $params = array('idnumber' => $idnumber);
            $id = $DB->get_field_select('course_categories', 'id', 'idnumber = :idnumber', $params, IGNORE_MISSING);

            // Little hack to be able to differenciate between the cache not set and a category not found.
            if ($id === false) {
                $id = -1;
            }

            $cache->set($cachekey, $id);
        }

        // Little hack to be able to differenciate between the cache not set and a category not found.
        if ($id == -1) {
            $id = false;
        }

        return $id;
    }

    /**
     * Resolve a category by path.
     *
     * @param array $path category names indexed from parent to children.
     * @return int category ID.
     * @throws dml_exception|coding_exception
     */
    public static function resolve_category_by_path(array $path) {
        global $DB;
        $cache = cache::make('enrol_database', 'helper');
        $cachekey = 'cat_path_' . serialize($path);
        if (($id = $cache->get($cachekey)) === false) {
            $parent = 0;
            $sql = 'name = :name AND parent = :parent';
            while ($name = array_shift($path)) {
                $params = array('name' => $name, 'parent' => $parent);
                if ($records = $DB->get_records_select('course_categories', $sql, $params, null, 'id, parent')) {
                    if (count($records) > 1) {
                        // Too many records with the same name!
                        $id = -1;
                        break;
                    }
                    $record = reset($records);
                    $id = $record->id;
                    $parent = $record->id;
                } else {
                    // Not found.
                    $id = -1;
                    break;
                }
            }
            $cache->set($cachekey, $id);
        }

        // We save -1 when the category has not been found to be able to know if the cache was set.
        if ($id == -1) {
            $id = false;
        }
        return $id;
    }
}
