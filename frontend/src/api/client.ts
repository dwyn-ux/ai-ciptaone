/**
 * API Client for AI Ciptaone Router
 */

export interface ApiErrorEnvelope {
  error: {
    code: string;
    message: string;
    request_id: string;
    details?: Record<string, any>;
  };
}

export class ApiError extends Error {
  code: string;
  requestId: string;
  details?: Record<string, any>;
  statusCode: number;

  constructor(message: string, code: string, requestId: string, statusCode: number, details?: Record<string, any>) {
    super(message);
    this.name = "ApiError";
    this.code = code;
    this.requestId = requestId;
    this.statusCode = statusCode;
    this.details = details;
  }
}

export async function apiFetch<T>(endpoint: string, options: RequestInit = {}): Promise<T> {
  const config: RequestInit = {
    ...options,
    credentials: "include", // Ensure session cookies are sent and received
    headers: {
      "Content-Type": "application/json",
      ...(options.headers || {}),
    },
  };

  const response = await fetch(endpoint, config);

  if (response.status === 204) {
    return {} as T;
  }

  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    const err = data.error || {};
    throw new ApiError(
      err.message || "Terjadi kesalahan pada server.",
      err.code || "unknown_error",
      err.request_id || "req_unknown",
      response.status,
      err.details
    );
  }

  return data as T;
}

export async function uploadFile<T>(endpoint: string, formData: FormData): Promise<T> {
  const response = await fetch(endpoint, {
    method: "POST",
    credentials: "include",
    body: formData,
  });

  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    const err = data.error || {};
    throw new ApiError(
      err.message || "Upload gagal.",
      err.code || "upload_failed",
      err.request_id || "req_unknown",
      response.status,
      err.details
    );
  }

  return data as T;
}
