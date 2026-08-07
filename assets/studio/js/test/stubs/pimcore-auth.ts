export function useUser(): { id: number } {
  return { id: 1 }
}

export function isAllowed(_permission: string): boolean {
  return true
}
