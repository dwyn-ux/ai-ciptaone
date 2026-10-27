import abc
from typing import Any, Dict, Optional


class BasePaymentAdapter(abc.ABC):
    @abc.abstractmethod
    async def create_invoice(
        self,
        order_id: str,
        amount_minor: int,
        currency: str,
        user_id: str,
        user_email: str,
    ) -> Dict[str, Any]:
        """
        Creates an invoice or checkout session.
        Returns dictionary containing checkout instructions, reference info, and checkout_url if applicable.
        """
        pass

    @abc.abstractmethod
    async def verify_webhook(self, headers: Dict[str, str], raw_body: bytes) -> Dict[str, Any]:
        """
        Verifies signature and authenticity of incoming webhook.
        Returns parsed event data or raises AppError(PAYMENT_VERIFICATION_FAILED).
        """
        pass
