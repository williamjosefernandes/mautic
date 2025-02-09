<?php

namespace Mautic\PointBundle\Controller\Api;

use Mautic\ApiBundle\Controller\CommonApiController;
use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\CoreBundle\Helper\IpLookupHelper;
use Mautic\LeadBundle\Controller\LeadAccessTrait;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\PointBundle\Entity\Point;
use Mautic\PointBundle\Model\PointModel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;

/**
 * @extends CommonApiController<Point>
 */
class PointApiController extends CommonApiController
{
    use LeadAccessTrait;

    /**
     * @var LeadModel
     */
    protected $leadModel;

    /**
     * @var PointModel|null
     */
    protected $model = null;

    public function initialize(ControllerEvent $event)
    {
        $leadModel = $this->getModel('lead');
        \assert($leadModel instanceof LeadModel);

        $pointModel = $this->getModel('point');
        \assert($pointModel instanceof PointModel);

        $this->model            = $pointModel;
        $this->leadModel        = $leadModel;
        $this->entityClass      = Point::class;
        $this->entityNameOne    = 'point';
        $this->entityNameMulti  = 'points';
        $this->serializerGroups = ['pointDetails', 'categoryList', 'publishDetails'];

        parent::initialize($event);
    }

    /**
     * Return array of available point action types.
     */
    public function getPointActionTypesAction()
    {
        if (!$this->security->isGranted([$this->permissionBase.':view', $this->permissionBase.':viewown'])) {
            return $this->accessDenied();
        }

        $actionTypes = $this->model->getPointActions();
        $view        = $this->view(['pointActionTypes' => $actionTypes['list']]);

        return $this->handleView($view);
    }

    /**
     * Subtract points from a lead.
     *
     * @param int    $leadId
     * @param string $operator
     * @param int    $delta
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function adjustPointsAction(Request $request, IpLookupHelper $ipLookupHelper, $leadId, $operator, $delta)
    {
        $lead = $this->checkLeadAccess($leadId, 'edit');
        if ($lead instanceof Response) {
            return $lead;
        }

        try {
            $this->logApiPointChange($request, $ipLookupHelper, $lead, $delta, $operator);
        } catch (\Exception $e) {
            return $this->returnError($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        return $this->handleView($this->view(['success' => 1], Response::HTTP_OK));
    }

    /**
     * Log the lead points change.
     *
     * @param int $leadId
     * @param int $delta
     */
    protected function logApiPointChange(Request $request, IpLookupHelper $ipLookupHelper, $lead, $delta, $operator)
    {
        $trans      = $this->translator;
        $ip         = $ipLookupHelper->getIpAddress();
        $eventName  = InputHelper::clean($request->request->get('eventName', $trans->trans('mautic.lead.lead.submitaction.operator_'.$operator)));
        $actionName = InputHelper::clean($request->request->get('actionName', $trans->trans('mautic.lead.event.api')));

        $lead->adjustPoints($delta, $operator);
        $lead->addPointsChangeLogEntry('API', $eventName, $actionName, $delta, $ip);
        $this->leadModel->saveEntity($lead, false);
    }
}
