import { afterEach, describe, expect, it, vi } from 'vitest'

const { post, get, fetchImportJob, download } = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn(), fetchImportJob: vi.fn(), download: vi.fn() }))
vi.mock('../client', () => ({ api: { post, get } }))
vi.mock('@/utils/downloadFile', () => ({ downloadApiFile: download }))
vi.mock('../imports', () => ({ fetchImportJob }))

import { stereoNxApi } from '../stereoNx'

const job = (status: string) => ({ id: 8, status, total_items: 1, processed: 0, created_count: 0, skipped_count: 0, failed_count: 0, current_step: null, log_text: null, last_error: null })

describe('stereoNxApi.run', () => {
  it.each(['cs', 'en'] as const)('downloads the server PDF for the exact report via the authenticated file helper (%s)', async language => {
    download.mockResolvedValue({})
    await stereoNxApi.downloadProtocol('synthetic-token', 17, language)
    expect(download).toHaveBeenCalledWith(`/admin/imports/stereo-nx/uploads/synthetic-token/runs/17/pdf?lang=${language}`, 'stereo-nx-protocol-17.pdf')
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.resetAllMocks()
  })

  it('spustí job, polluje jeho stav a po doběhnutí stáhne výsledek', async () => {
    vi.useFakeTimers()
    post.mockResolvedValueOnce({ data: { job_id: 8, mode: 'dry_run', status: 'queued' } })
    fetchImportJob.mockResolvedValueOnce(job('queued')).mockResolvedValueOnce(job('running')).mockResolvedValueOnce(job('completed'))
    get.mockResolvedValueOnce({ data: { mode: 'dry_run', report: { ok: true } } })
    const seen: string[] = []

    const done = stereoNxApi.run('a'.repeat(32), 0, 'dry_run', true, current => seen.push(current.status))
    await vi.advanceTimersByTimeAsync(4000)

    await expect(done).resolves.toEqual({ ok: true })
    expect(post).toHaveBeenCalledWith(`/admin/imports/stereo-nx/uploads/${'a'.repeat(32)}/run`, { company: 0, mode: 'dry_run', blank_country_is_cz: true, match_bank_account_series: false })
    expect(seen).toEqual(['queued', 'running', 'completed'])
    expect(get).toHaveBeenCalledWith(`/admin/imports/stereo-nx/uploads/${'a'.repeat(32)}/runs/8`)
  })

  it('sends explicit bank-series approval with a new run', async () => {
    post.mockResolvedValueOnce({ data: { job_id: 8 } })
    await expect(stereoNxApi.start('synthetic-token', 0, 'dry_run', false, true)).resolves.toBe(8)
    expect(post).toHaveBeenCalledWith('/admin/imports/stereo-nx/uploads/synthetic-token/run', {
      company: 0, mode: 'dry_run', blank_country_is_cz: false, match_bank_account_series: true,
    })
  })

  it('job bez výsledku vrátí chybu serveru', async () => {
    post.mockResolvedValueOnce({ data: { job_id: 8 } })
    fetchImportJob.mockResolvedValueOnce(job('failed'))
    const error = Object.assign(new Error('409'), { response: { status: 409, data: { error: { message: 'Nahraná záloha se změnila.' } } } })
    get.mockRejectedValueOnce(error)

    await expect(stereoNxApi.run('b'.repeat(32), 1, 'import', false)).rejects.toBe(error)
  })
})
