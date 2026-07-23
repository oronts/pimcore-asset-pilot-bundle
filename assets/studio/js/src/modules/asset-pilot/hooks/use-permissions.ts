import { isAllowed } from '@pimcore/studio-ui-bundle/modules/auth'

export interface Permissions {
  view: boolean
  operate: boolean
  admin: boolean
  tagsAssignment: boolean
}

export function usePermissions(): Permissions {
  return {
    view: isAllowed('asset_pilot_view'),
    operate: isAllowed('asset_pilot_operate'),
    admin: isAllowed('asset_pilot_admin'),
    tagsAssignment: isAllowed('tags_assignment'),
  }
}
