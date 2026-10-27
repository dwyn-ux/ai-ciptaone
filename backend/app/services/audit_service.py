import json
import uuid
from datetime import datetime, timezone
from typing import Any, Dict, List, Optional
from app.core.database import db


class AuditService:
    async def log_action(
        self,
        admin_user_id: str,
        action: str,
        target_type: str,
        target_id: str,
        metadata: Optional[Dict[str, Any]] = None,
    ) -> str:
        audit_id = f"aud_{uuid.uuid4().hex[:16]}"
        now_iso = datetime.now(timezone.utc).isoformat()
        meta_json = json.dumps(metadata or {})

        await db.execute(
            """
            INSERT INTO admin_audit_logs (id, admin_user_id, action, target_type, target_id, metadata_json, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            """,
            [audit_id, admin_user_id, action, target_type, target_id, meta_json, now_iso]
        )
        return audit_id

    async def list_audit_logs(self, limit: int = 50, offset: int = 0) -> List[Dict[str, Any]]:
        rows = await db.fetch_all(
            """
            SELECT l.id, l.admin_user_id, u.email_normalized as admin_email,
                   l.action, l.target_type, l.target_id, l.metadata_json, l.created_at
            FROM admin_audit_logs l
            LEFT JOIN users u ON l.admin_user_id = u.id
            ORDER BY l.created_at DESC
            LIMIT ? OFFSET ?
            """,
            [limit, offset]
        )
        results = []
        for r in rows:
            meta = None
            if r["metadata_json"]:
                try:
                    meta = json.loads(r["metadata_json"])
                except Exception:
                    pass
            results.append({
                "id": r["id"],
                "admin_user_id": r["admin_user_id"],
                "admin_email": r["admin_email"] or r["admin_user_id"],
                "action": r["action"],
                "target_type": r["target_type"],
                "target_id": r["target_id"],
                "metadata": meta,
                "created_at": r["created_at"],
            })
        return results


audit_service = AuditService()
