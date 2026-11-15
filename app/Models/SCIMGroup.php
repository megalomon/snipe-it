<?php

namespace App\Models;

use ArieTimmerman\Laravel\SCIMServer\Exceptions\SCIMException;

/**
 * @mixin IdeHelperSCIMGroup
 */
class SCIMGroup extends Group
{
    protected $table = 'permission_groups';

    /**
     * Override the default firstOrNew() to always return a fresh instance
     * when called with an empty conditions array.
     *
     * The upstream SCIM library's create path lives at
     * vendor/arietimmerman/laravel-scim-server/src/Http/Controllers/ResourceController.php,
     * and hardcodes a username-only lookup for the "does this resource
     * already exist" check:
     *
     *   $resourceObject = $class::firstOrNew(
     *       $request->has('userName') ? ['username' => $input['userName']] : []
     *   );
     *
     * For User POSTs that's fine: firstOrNew(['username' => ...]) resolves
     * to an existing user or a new one. For Group POSTs the ternary always
     * lands on the empty-array branch (groups have no userName in their
     * SCIM payload), and Laravel's Model::firstOrNew([]) issues a WHERE
     * with no conditions, which returns THE FIRST ROW OF THE TABLE. Every
     * subsequent group POST resolves to that same row and the attribute
     * mappers overwrite its name / externalId / members instead of
     * creating a new group. The observed symptom is that every synced
     * group collapses into whatever group happens to be id=1, and every
     * SCIM-provisioned user ends up as a member of that one group. If the
     * pre-existing id=1 group carried elevated permissions (e.g. an
     * "Admins" group created before SCIM sync was enabled), every synced
     * user auto-elevated to those permissions. See #19493.
     *
     * Overriding firstOrNew here is the least invasive fix: SCIM's Group
     * route creates go through this subclass (via SnipeSCIMConfig::getGroupClass),
     * so the create path always returns a new model. Any callers passing
     * real conditions get the default Eloquent behavior.
     */
    public static function firstOrNew(array $attributes = [], array $values = [])
    {
        if (empty($attributes)) {
            $existing = self::adoptableGroupForCreate();

            if ($existing !== null) {
                return $existing;
            }

            // Groups carry permissions, so with this setting only groups an
            // administrator created in Snipe-IT can be provisioned.
            if (Setting::getSettings()?->scim_existing_groups_only) {
                throw (new SCIMException(trans('admin/settings/general.scim_group_not_found', ['name' => (string) request()->input('displayName')])))->setCode(403);
            }

            return new self;
        }

        return parent::firstOrNew($attributes, $values);
    }

    /**
     * Request attribute marking a POST that took over an existing group.
     */
    public const ADOPTED_REQUEST_ATTRIBUTE = 'scim_adopted_group';

    /**
     * A POST for a group whose name already exists locally adopts that group
     * when it isn't linked to any SCIM identity yet (no externalId), the
     * same way user POSTs adopt an existing username. This lets an identity
     * provider (e.g. Univention Nubus, which only looks groups up by
     * externalId) take over groups that were created by hand before SCIM
     * was set up, keeping their permissions. A same-named group already
     * linked to another externalId is a real conflict and is left alone, so
     * the request still fails the displayName uniqueness check.
     */
    private static function adoptableGroupForCreate(): ?self
    {
        $request = request();
        $name = $request->input('displayName');

        if (! $request->isMethod('POST') || ! is_string($name) || $name === '') {
            return null;
        }

        $existing = self::where('name', $name)->first();

        if ($existing === null || ! self::isAdoptableOnCreate($existing)) {
            return null;
        }

        // Lets the displayName uniqueness rule recognise the row as ours.
        $request->attributes->set('scim_in_flight_resource', $existing);
        $request->attributes->set(self::ADOPTED_REQUEST_ATTRIBUTE, true);

        return $existing;
    }

    /**
     * Whether a SCIM POST for $group's name may take $group over: only on a
     * create, and only while the group isn't linked to a SCIM identity.
     * Also consulted by the displayName uniqueness rule, which runs on the
     * raw payload before firstOrNew() is reached.
     */
    public static function isAdoptableOnCreate(Group $group): bool
    {
        return request()->isMethod('POST') && empty($group->scim_externalid);
    }

    // Have to re-define these here because Eloquent will try to 'guess' a
    // foreign key of s_c_i_m_group_id from SCIMGroup. Mirrors the reason
    // SCIMUser re-defines groups() the same way.
    public function users()
    {
        return $this->belongsToMany(\App\Models\User::class, 'users_groups', 'group_id', 'user_id');
    }

    public function members()
    {
        return $this->users();
    }
}
