import { api } from './client'
import { appendStepUpProof, stepUpProofBody, type EpoStepUpProof } from './epoSubmissions'

export type EetEnvironment = 'playground' | 'production'
export type EetRegime = 'standard' | 'exempt' | 'eet_off'
export type EetUnitKind = 'premises' | 'online' | 'vehicle' | 'mobile' | 'other'
export type EetPermissionLevel = 'none' | 'read' | 'write'

export interface EetCapabilities {
  contract_version: string
  enabled: boolean
  environment: EetEnvironment
  regime: EetRegime
  eic: string | null
  active_from: string | null
  supported_scenarios: string[]
  unsupported_scenarios: string[]
  permissions: Record<string, EetPermissionLevel>
  default_device_id: number | null
  has_active_certificate: boolean
  certificate_expires_at: string | null
  commercial_license: boolean
}

export interface EetSettings {
  enabled: boolean
  environment: EetEnvironment
  eic: string | null
  active_from: string | null
  active_to: string | null
  regime: EetRegime
  exemption_reason: string | null
  default_device_id: number | null
  response_timeout_ms: number
  dispatch_blocked: boolean
  row_version: number
  updated_by: number | null
  updated_at: string | null
}

export type EetSettingsPatch = Partial<Pick<EetSettings,
  'enabled' | 'environment' | 'eic' | 'active_from' | 'active_to' | 'regime'
  | 'exemption_reason' | 'default_device_id' | 'response_timeout_ms'>> & { row_version?: number }

export interface EetActivationObstacle {
  code: string
  message: string
}

export interface EetSettingsHistoryItem {
  id: number
  changed_at: string | null
  changed_by: number | null
  snapshot: Partial<EetSettings>
}

export interface EetSettingsResponse {
  settings: EetSettings
  activation_obstacles: EetActivationObstacle[]
  history?: EetSettingsHistoryItem[]
}

export interface EetUnit {
  id: number
  unit_number: number
  name: string
  kind: EetUnitKind
  valid_from: string
  valid_to: string | null
  notified_to_fs: boolean
  created_at: string | null
  updated_at: string | null
}

export interface EetUnitInput {
  unit_number: number
  name: string
  kind: EetUnitKind
  valid_from: string
  valid_to?: string | null
  notified_to_fs?: boolean
}

export interface EetDevice {
  id: number
  unit_id: number
  code: string
  name: string | null
  cash_register_id: number | null
  active: boolean
  sequence_year: number | null
  next_sequence: number
  unit_number: number
  unit_name: string
  unit_valid_from: string
  unit_valid_to: string | null
  created_at: string | null
  updated_at: string | null
}

export interface EetDeviceInput {
  unit_id: number
  code: string
  name?: string | null
  cash_register_id?: number | null
  active?: boolean
}

export type EetCertificateExpiryWarning = 'expired' | 'critical' | 'warning' | null

export interface EetCertificate {
  id: number
  environment: EetEnvironment
  eic: string
  subject_cn: string | null
  serial_number: string | null
  fingerprint_sha256: string
  issuer: string | null
  not_before: string
  not_after: string
  label: string | null
  status: string
  uploaded_by: number | null
  created_at: string | null
  retired_at: string | null
  valid_now: boolean
  expires_in_days: number
  expiry_warning: EetCertificateExpiryWarning
}

export interface EetCertificateUpload {
  file: File
  password: string
  environment: EetEnvironment
  label?: string
  proof: EpoStepUpProof
}

export interface EetDiagnosticsResult {
  ok: boolean
  outcome: string
  error_code: number | null
  message: string | null
  duration_ms: number
  environment: EetEnvironment
  device_id: number
  certificate_id: number
}

/** Tvary níže pro budoucí endpointy (platby, evidence, fronta) doplní navazující práce. */
export type EetMoney = string

export interface EetPaymentRequest {
  [key: string]: unknown
}

export interface EetPaymentIntentRequest {
  [key: string]: unknown
}

export interface EetRecordListParams {
  cursor?: string
  limit?: number
  status?: string
  from?: string
  to?: string
}

export interface EetExportParams {
  from?: string
  to?: string
}

export const eetApi = {
  capabilities: () => api.get<EetCapabilities>('/eet/capabilities').then(r => r.data),

  settings: () => api.get<EetSettingsResponse>('/eet/settings').then(r => r.data),
  saveSettings: (patch: EetSettingsPatch) =>
    api.put<EetSettingsResponse>('/eet/settings', patch).then(r => r.data),

  units: () => api.get<{ items: EetUnit[] }>('/eet/units').then(r => r.data.items),
  createUnit: (input: EetUnitInput) => api.post<EetUnit>('/eet/units', input).then(r => r.data),
  updateUnit: (id: number, input: Partial<EetUnitInput>) =>
    api.put<EetUnit>(`/eet/units/${id}`, input).then(r => r.data),
  endUnit: (id: number, validTo?: string) =>
    api.delete<EetUnit>(`/eet/units/${id}`, { params: validTo ? { valid_to: validTo } : undefined }).then(r => r.data),

  devices: () => api.get<{ items: EetDevice[] }>('/eet/devices').then(r => r.data.items),
  createDevice: (input: EetDeviceInput) => api.post<EetDevice>('/eet/devices', input).then(r => r.data),
  updateDevice: (id: number, input: Partial<EetDeviceInput>) =>
    api.put<EetDevice>(`/eet/devices/${id}`, input).then(r => r.data),
  deactivateDevice: (id: number) => api.delete<EetDevice>(`/eet/devices/${id}`).then(r => r.data),

  certificates: () => api.get<{ items: EetCertificate[] }>('/eet/certificates').then(r => r.data.items),
  uploadCertificate: (payload: EetCertificateUpload) => {
    const data = new FormData()
    data.append('file', payload.file, payload.file.name)
    data.append('environment', payload.environment)
    data.append('pfx_password', payload.password)
    if (payload.label) data.append('label', payload.label)
    appendStepUpProof(data, payload.proof)
    return api.post<EetCertificate>('/eet/certificates', data, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
  deactivateCertificate: (id: number, proof: EpoStepUpProof) =>
    api.post<EetCertificate>(`/eet/certificates/${id}/deactivate`, stepUpProofBody(proof)).then(r => r.data),

  diagnostics: (deviceId?: number | null) =>
    api.post<EetDiagnosticsResult>('/eet/diagnostics', deviceId ? { device_id: deviceId } : {}).then(r => r.data),

  // Budoucí endpointy (spec, sekce 2): jen tenké metody, tvary upřesní navazující práce.
  previewPayment: (body: EetPaymentRequest) =>
    api.post<Record<string, unknown>>('/eet/payments/preview', body).then(r => r.data),
  createPayment: (body: EetPaymentRequest, idempotencyKey: string) =>
    api.post<Record<string, unknown>>('/eet/payments', body, { headers: { 'Idempotency-Key': idempotencyKey } }).then(r => r.data),
  payment: (publicId: string) =>
    api.get<Record<string, unknown>>(`/eet/payments/${encodeURIComponent(publicId)}`).then(r => r.data),

  createPaymentIntent: (body: EetPaymentIntentRequest) =>
    api.post<Record<string, unknown>>('/eet/payment-intents', body).then(r => r.data),
  confirmPaymentIntent: (publicId: string, idempotencyKey: string) =>
    api.post<Record<string, unknown>>(`/eet/payment-intents/${encodeURIComponent(publicId)}/confirm`, {}, {
      headers: { 'Idempotency-Key': idempotencyKey },
    }).then(r => r.data),
  cancelPaymentIntent: (publicId: string) =>
    api.post<Record<string, unknown>>(`/eet/payment-intents/${encodeURIComponent(publicId)}/cancel`).then(r => r.data),

  records: (params: EetRecordListParams = {}) =>
    api.get<Record<string, unknown>>('/eet/records', { params }).then(r => r.data),
  record: (id: number) => api.get<Record<string, unknown>>(`/eet/records/${id}`).then(r => r.data),
  retryRecord: (id: number) => api.post<Record<string, unknown>>(`/eet/records/${id}/retry`).then(r => r.data),
  previewCorrection: (id: number, body: Record<string, unknown>) =>
    api.post<Record<string, unknown>>(`/eet/records/${id}/corrections/preview`, body).then(r => r.data),
  createCorrection: (id: number, body: Record<string, unknown>, idempotencyKey: string) =>
    api.post<Record<string, unknown>>(`/eet/records/${id}/corrections`, body, {
      headers: { 'Idempotency-Key': idempotencyKey },
    }).then(r => r.data),

  queue: () => api.get<Record<string, unknown>>('/eet/queue').then(r => r.data),
  exportCsv: (params: EetExportParams = {}) =>
    api.get<Blob>('/eet/export', { params, responseType: 'blob' }).then(r => r.data),
  invoiceSummary: (invoiceId: number) =>
    api.get<Record<string, unknown>>(`/invoices/${invoiceId}/eet`).then(r => r.data),
}
