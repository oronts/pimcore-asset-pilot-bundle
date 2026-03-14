import { createContext, useContext, useState, useEffect } from 'react'
import { assetPilotApi } from '../services/api'

export interface Permissions {
  view: boolean
  operate: boolean
  admin: boolean
  loading: boolean
}

const defaultPerms: Permissions = { view: false, operate: false, admin: false, loading: true }

export const PermissionContext = createContext<Permissions>(defaultPerms)

export function usePermissions(): Permissions {
  return useContext(PermissionContext)
}

export function usePermissionsFetch(): Permissions {
  const [perms, setPerms] = useState<Permissions>(defaultPerms)
  useEffect(() => {
    assetPilotApi.getPermissions()
      .then(data => setPerms({ ...data, loading: false }))
      .catch(() => setPerms({ view: false, operate: false, admin: false, loading: false }))
  }, [])
  return perms
}
