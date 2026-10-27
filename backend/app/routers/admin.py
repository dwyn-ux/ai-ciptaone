import os
from typing import Any, Dict, List, Optional
from fastapi import APIRouter, Depends, Query, status
from fastapi.responses import FileResponse
from app.config import settings
from app.core.database import db
from app.core.errors import AppError, ErrorCode
from app.models.schemas import (
    AdminBalanceAdjustRequest,
    AdminModelUpdateRequest,
    AdminTopupReviewRequest,
    ModelItem,
    ProviderItem,
    UserResponse,
)
from app.routers.deps import get_current_admin_user
from app.services.audit_service import audit_service
from app.services.topup_service import topup_service
from app.services.wallet_service import wallet_service

router = APIRouter(prefix="/api/v1/admin", tags=["Admin Operations"])


# ------------------ Models & Providers Management ------------------

@router.get("/models", response_model=List[ModelItem])
async def admin_list_models(admin: UserResponse = Depends(get_current_admin_user)):
    rows = await db.fetch_all(
        """
        SELECT m.id, m.public_id, m.provider_id, p.slug as provider_slug, p.display_name,
               m.upstream_model, m.enabled, m.input_price_per_million, m.output_price_per_million,
               m.currency, m.max_input_tokens, m.max_output_tokens, m.supports_streaming, m.pricing_version
        FROM models m
        JOIN providers p ON m.provider_id = p.id
        ORDER BY m.public_id ASC
        """
    )
    return [
        ModelItem(
            id=r["id"],
            public_id=r["public_id"],
            provider_id=r["provider_id"],
            provider_slug=r["provider_slug"],
            display_name=r["display_name"],
            upstream_model=r["upstream_model"],
            enabled=bool(r["enabled"]),
            input_price_per_million=r["input_price_per_million"],
            output_price_per_million=r["output_price_per_million"],
            currency=r["currency"],
            max_input_tokens=r["max_input_tokens"],
            max_output_tokens=r["max_output_tokens"],
            supports_streaming=bool(r["supports_streaming"]),
            pricing_version=r["pricing_version"],
        )
        for r in rows
    ]


@router.patch("/models/{model_id}")
async def admin_update_model(
    model_id: str,
    req: AdminModelUpdateRequest,
    admin: UserResponse = Depends(get_current_admin_user),
):
    updates = []
    params = []

    if req.enabled is not None:
        updates.append("enabled = ?")
        params.append(1 if req.enabled else 0)
    if req.input_price_per_million is not None:
        updates.append("input_price_per_million = ?")
        params.append(req.input_price_per_million)
    if req.output_price_per_million is not None:
        updates.append("output_price_per_million = ?")
        params.append(req.output_price_per_million)
    if req.max_input_tokens is not None:
        updates.append("max_input_tokens = ?")
        params.append(req.max_input_tokens)
    if req.max_output_tokens is not None:
        updates.append("max_output_tokens = ?")
        params.append(req.max_output_tokens)

    if not updates:
        return {"status": "unchanged"}

    updates.append("pricing_version = pricing_version + 1")
    updates.append("updated_at = datetime('now')")
    sql = f"UPDATE models SET {', '.join(updates)} WHERE id = ? OR public_id = ?"
    params.extend([model_id, model_id])

    await db.execute(sql, params)
    await audit_service.log_action(
        admin_user_id=admin.id,
        action="update_model",
        target_type="model",
        target_id=model_id,
        metadata=req.model_dump(exclude_none=True),
    )
    return {"status": "success", "message": "Model berhasil diperbarui."}


@router.get("/providers", response_model=List[ProviderItem])
async def admin_list_providers(admin: UserResponse = Depends(get_current_admin_user)):
    rows = await db.fetch_all("SELECT * FROM providers ORDER BY slug ASC")
    return [
        ProviderItem(
            id=r["id"],
            slug=r["slug"],
            display_name=r["display_name"],
            enabled=bool(r["enabled"]),
            commercial_use_approved=bool(r["commercial_use_approved"]),
            terms_checked_at=r["terms_checked_at"],
        )
        for r in rows
    ]


@router.patch("/providers/{provider_id}")
async def admin_update_provider(
    provider_id: str,
    enabled: bool,
    admin: UserResponse = Depends(get_current_admin_user),
):
    val = 1 if enabled else 0
    await db.execute(
        "UPDATE providers SET enabled = ?, updated_at = datetime('now') WHERE id = ? OR slug = ?",
        [val, provider_id, provider_id],
    )
    await audit_service.log_action(
        admin_user_id=admin.id,
        action="toggle_provider",
        target_type="provider",
        target_id=provider_id,
        metadata={"enabled": enabled},
    )
    return {"status": "success", "message": "Status provider berhasil diperbarui."}


# ------------------ Top-ups Management ------------------

@router.get("/topups")
async def admin_list_topups(
    status_filter: Optional[str] = Query(default=None),
    limit: int = Query(default=50, ge=1, le=100),
    admin: UserResponse = Depends(get_current_admin_user),
):
    query = """
        SELECT t.id, t.user_id, u.email_normalized as user_email, t.order_id,
               t.amount_minor, t.currency, t.credits_to_grant, t.payment_method,
               t.status, t.proof_file_key, t.reviewed_by, t.reviewed_at, t.rejection_reason,
               t.expires_at, t.created_at, t.paid_at
        FROM topups t
        JOIN users u ON t.user_id = u.id
    """
    params = []
    if status_filter:
        query += " WHERE t.status = ?"
        params.append(status_filter)

    query += " ORDER BY t.created_at DESC LIMIT ?"
    params.append(limit)

    return await db.fetch_all(query, params)


@router.post("/topups/{order_id}/review")
async def admin_review_topup(
    order_id: str,
    req: AdminTopupReviewRequest,
    admin: UserResponse = Depends(get_current_admin_user),
):
    action = "paid" if req.action == "approve" else "rejected"
    return await topup_service.review_topup(
        order_id=order_id,
        action=action,
        admin_user_id=admin.id,
        rejection_reason=req.rejection_reason,
    )


@router.get("/topups/{order_id}/proof")
async def admin_get_proof(
    order_id: str,
    admin: UserResponse = Depends(get_current_admin_user),
):
    topup = await topup_service.get_topup_by_id_or_order(order_id)
    proof_key = topup.get("proof_file_key")
    if not proof_key:
        raise AppError(code=ErrorCode.NOT_FOUND, message="Bukti transfer belum diunggah untuk order ini.", status_code=404)

    file_path = os.path.join(settings.PROOF_UPLOAD_DIR, proof_key)
    if not os.path.exists(file_path):
        raise AppError(code=ErrorCode.NOT_FOUND, message="File bukti tidak ditemukan di storage server.", status_code=404)

    return FileResponse(file_path)


# ------------------ Users & Wallets Management ------------------

@router.get("/users")
async def admin_list_users(
    limit: int = Query(default=50, ge=1, le=100),
    offset: int = Query(default=0, ge=0),
    admin: UserResponse = Depends(get_current_admin_user),
):
    rows = await db.fetch_all(
        """
        SELECT u.id, u.email_normalized as email, u.status, u.role, u.created_at,
               COALESCE(w.balance_credits, 0) as balance_credits
        FROM users u
        LEFT JOIN wallets w ON u.id = w.user_id
        ORDER BY u.created_at DESC
        LIMIT ? OFFSET ?
        """,
        [limit, offset]
    )
    return rows


@router.post("/users/balance-adjust")
async def admin_adjust_balance(
    req: AdminBalanceAdjustRequest,
    admin: UserResponse = Depends(get_current_admin_user),
):
    new_balance = await wallet_service.adjust_balance_admin(
        admin_user_id=admin.id,
        target_user_id=req.user_id,
        amount_credits=req.amount_credits,
        reason=req.reason,
    )
    return {
        "status": "success",
        "message": f"Saldo pengguna berhasil disesuaikan. Saldo baru: {new_balance} kredit.",
        "new_balance": new_balance,
    }


# ------------------ Audit Logs ------------------

@router.get("/audit-logs")
async def admin_get_audit_logs(
    limit: int = Query(default=50, ge=1, le=100),
    offset: int = Query(default=0, ge=0),
    admin: UserResponse = Depends(get_current_admin_user),
):
    return await audit_service.list_audit_logs(limit=limit, offset=offset)
