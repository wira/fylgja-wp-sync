<?php

if (!defined('ABSPATH')) {
    exit;
}

class Fylgja_Queue_Collapser {

    public function collapse_for_row($storage, $current_row): array {
        $action      = $current_row->action;
        $object_type = $current_row->object_type;
        $object_id   = (int) $current_row->object_id;
        $current_id  = (int) $current_row->id;

        $deleted_same = $storage->delete_older_same($action, $object_type, $object_id, $current_id);
        $deleted_supersedes = 0;
        if ($action === 'delete') {
            $deleted_supersedes = $storage->delete_older_upserts_for_delete($object_type, $object_id, $current_id);
        }

        return [
            'collapsed_same'       => $deleted_same,
            'collapsed_supersedes' => $deleted_supersedes,
        ];
    }
}
