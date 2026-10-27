/**
 * Cloudflare Worker: D1 Gateway API
 * Provides authenticated, secure, and transactional access to Cloudflare D1 for FastAPI backend.
 */

export interface Env {
  DB: D1Database;
  D1_SERVICE_TOKEN: string;
}

interface QueryPayload {
  sql: string;
  params?: unknown[];
}

interface BatchPayload {
  statements: QueryPayload[];
}

const MAX_BODY_BYTES = 1024 * 1024; // 1MB limit for query payload

function jsonResponse(data: unknown, status = 200): Response {
  return new Response(JSON.stringify(data), {
    status,
    headers: {
      "Content-Type": "application/json",
      "Cache-Control": "no-store",
    },
  });
}

function verifyAuth(request: Request, env: Env): boolean {
  const authHeader = request.headers.get("Authorization");
  if (!authHeader) return false;

  const parts = authHeader.split(" ");
  if (parts.length !== 2 || parts[0] !== "Bearer") return false;

  const token = parts[1];
  const expectedToken = env.D1_SERVICE_TOKEN;

  if (!expectedToken || token.length !== expectedToken.length) {
    return false;
  }

  // Constant-time string comparison to prevent timing attacks
  let mismatch = 0;
  for (let i = 0; i < token.length; i++) {
    mismatch |= token.charCodeAt(i) ^ expectedToken.charCodeAt(i);
  }
  return mismatch === 0;
}

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    const url = new URL(request.url);

    // Route: GET /health
    if (request.method === "GET" && url.pathname === "/health") {
      try {
        const ping = await env.DB.prepare("SELECT 1 as ok").first();
        return jsonResponse({ status: "healthy", d1: ping?.ok === 1 });
      } catch (err: unknown) {
        const errorMsg = err instanceof Error ? err.message : String(err);
        return jsonResponse({ status: "unhealthy", error: errorMsg }, 500);
      }
    }

    // All other routes require authentication
    if (!verifyAuth(request, env)) {
      return jsonResponse({ error: "Unauthorized: Invalid or missing service token" }, 401);
    }

    // Check payload size
    const contentLength = parseInt(request.headers.get("content-length") || "0", 10);
    if (contentLength > MAX_BODY_BYTES) {
      return jsonResponse({ error: "Payload too large" }, 413);
    }

    // Route: POST /query
    if (request.method === "POST" && url.pathname === "/query") {
      try {
        const body = (await request.json()) as QueryPayload;
        if (!body.sql || typeof body.sql !== "string") {
          return jsonResponse({ error: "Invalid 'sql' in payload" }, 400);
        }

        const params = Array.isArray(body.params) ? body.params : [];
        const stmt = env.DB.prepare(body.sql).bind(...params);

        const result = await stmt.all();
        return jsonResponse({
          success: true,
          results: result.results,
          meta: result.meta,
        });
      } catch (err: unknown) {
        const errorMsg = err instanceof Error ? err.message : String(err);
        return jsonResponse({ success: false, error: errorMsg }, 400);
      }
    }

    // Route: POST /batch (Executes statements atomically in D1 batch transaction)
    if (request.method === "POST" && url.pathname === "/batch") {
      try {
        const body = (await request.json()) as BatchPayload;
        if (!Array.isArray(body.statements) || body.statements.length === 0) {
          return jsonResponse({ error: "Invalid or empty 'statements' array" }, 400);
        }

        const stmts = body.statements.map((s) => {
          if (!s.sql || typeof s.sql !== "string") {
            throw new Error("Invalid SQL statement inside batch");
          }
          const params = Array.isArray(s.params) ? s.params : [];
          return env.DB.prepare(s.sql).bind(...params);
        });

        const batchResults = await env.DB.batch(stmts);
        return jsonResponse({
          success: true,
          results: batchResults.map((r) => ({
            results: r.results,
            meta: r.meta,
            success: r.success,
          })),
        });
      } catch (err: unknown) {
        const errorMsg = err instanceof Error ? err.message : String(err);
        return jsonResponse({ success: false, error: errorMsg }, 400);
      }
    }

    return jsonResponse({ error: "Not Found" }, 404);
  },
};
