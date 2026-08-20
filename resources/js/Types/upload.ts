/**
 * What the upload screen is allowed to promise.
 *
 * Everything here is measured from the installation by `UploadScreenQuery` —
 * whether the configured storage can take a direct write, and what each kind's
 * ceiling actually is once PHP's own limits are taken into account. Nothing is
 * re-derived in the browser.
 */
export interface UploadKind {
    value: string;
    /** What SaniTube is configured to accept, in bytes. */
    maximum_bytes: number;
    /** The lower of that and PHP's own limit on the relayed path. */
    effective_maximum_bytes: number;
    /** True when the server, not SaniTube's setting, is the binding limit. */
    limited_by_php: boolean;
    /** Empty means any type is accepted for this kind. */
    accepted_types: string[];
}

export interface UploadCapability {
    /** Whether the configured provider can take a write that never enters PHP. */
    direct: boolean;
    php_post_limit_bytes: number;
    kinds: UploadKind[];
}

export type UploadState = 'queued' | 'uploading' | 'verifying' | 'done' | 'failed';

export interface UploadItem {
    /** Stable across re-renders; the file name is not unique. */
    id: number;
    name: string;
    size: number;
    state: UploadState;
    /** 0–100. Real on the direct path, coarse on the relayed one. */
    progress: number;
    /** A refusal code, never a sentence from the server. */
    code: string | null;
    assetUuid: string | null;
    duplicate: boolean;
}
